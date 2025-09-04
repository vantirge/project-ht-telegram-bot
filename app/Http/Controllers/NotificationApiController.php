<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\SecurityService;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use GuzzleHttp\Client as GuzzleClient;


class NotificationApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Нормализуем синонимы полей до валидации
        $normalized = $request->all();
        if (!isset($normalized['fio']) && isset($normalized['full_name'])) {
            $normalized['fio'] = $normalized['full_name'];
        }
        if (!isset($normalized['session_number']) && isset($normalized['session'])) {
            $normalized['session_number'] = $normalized['session'];
        }
        if (!isset($normalized['test']) && isset($normalized['test'])) {
            $normalized['test'] = $normalized['test'];
        }

        $request->merge($normalized);

        // Валидация: поддерживаются три режима
        // 1) Широковещательно: только message
        // 2) Прямое произвольное: login + message
        // 3) Структурированное прямое: login + test + session_number + fio (+ result)
        $validator = Validator::make($request->all(), [
            'message' => ['sometimes', 'string', 'max:1000'],
            'login' => ['sometimes', 'string', 'max:50'],
            'test' => ['required_without:message', 'string', 'max:255'],
            'session_number' => ['required_without:message', 'string', 'max:50'],
            'fio' => ['required_without:message', 'string', 'max:255'],
            'result' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Режим 1: Широковещательно (message без login)
        if (!empty($data['message']) && empty($data['login'])) {
            $finalText = SecurityService::validateInput($data['message'], 'text');
            // Создание Notification; рассылку всем выполнит Observer
            $notification = Notification::create([
                'description' => $finalText,
                'is_broadcast' => true,
            ]);

            Log::info('Broadcast notification created', ['id' => $notification->id]);

            return response()->json([
                'status' => 'ok',
                'id' => $notification->id,
            ]);
        }

        // Режим 2: Прямое произвольное сообщение (login + message)
        if (!empty($data['message']) && !empty($data['login'])) {
            $login = SecurityService::validateInput($data['login'] ?? '', 'login');
            $finalText = SecurityService::validateInput($data['message'] ?? '', 'text');

            // Ищем Telegram-пользователя по логину
            $recipient = TelegramUser::where('user_login', $login)->first();
            if (!$recipient) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Пользователь с таким логином не найден',
                ], 404);
            }

            // Проверяем настройки уведомлений пользователя
            $chatDisabled = (bool)($recipient->chat_disabled ?? false);
            if ($chatDisabled) {
                // Create notification and queue to history with sent_at = null
                $notification = Notification::create([
                    'description' => $finalText,
                    'is_broadcast' => false,
                ]);

                DB::table('notification_history')->updateOrInsert([
                    'telegram_id' => $recipient->telegram_id,
                    'notification_id' => $notification->id,
                ], [
                    'sent_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'status' => 'skipped',
                    'reason' => 'notifications_disabled',
                    'id' => $notification->id,
                ]);
            }

            // Подготавливаем Telegram-клиент
            $guzzle = new GuzzleClient([
                'verify' => config('app.env') === 'local' ? false : true,
                'timeout' => 5.0,
                'connect_timeout' => 2.0,
            ]);
            $httpClient = new GuzzleHttpClient($guzzle);
            $telegram = new Api(
                config('services.telegram.bot_token'),
                false,
                $httpClient
            );

            try {
                $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => "🔔 {$finalText}"
                ]);

                $notification = Notification::create([
                    'description' => $finalText,
                    'is_broadcast' => false,
                ]);

                // Записываем отправку в историю
                DB::table('notification_history')->insert([
                    'telegram_id' => $recipient->telegram_id,
                    'notification_id' => $notification->id,
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'status' => 'ok',
                    'id' => $notification->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send custom direct notification', [
                    'login' => $login,
                    'telegram_id' => $recipient->telegram_id ?? null,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Не удалось отправить сообщение пользователю',
                ], 500);
            }
        }

        // Режим 3: Структурированное сообщение — отправляем ТОЛЬКО конкретному логину
        $login = SecurityService::validateInput($data['login'] ?? '', 'login');
        $test = SecurityService::validateInput($data['test'] ?? '', 'text');
        $sessionNumber = SecurityService::validateInput($data['session_number'] ?? '', 'text');
        $fio = SecurityService::validateInput($data['fio'] ?? '', 'text');
        $result = isset($data['result']) && $data['result'] !== null
            ? SecurityService::validateInput($data['result'], 'text')
            : null;

        // Формируем текст сообщения
        $resultText = !empty($result) ? $result : 'начат';
        
        // Если result пустой, используем "начат" по умолчанию
        if (empty($result)) {
            $resultText = 'начат';
        }
        
        // Преобразуем ФИО в родительный падеж
        $fioParts = explode(' ', trim($fio));
        if (count($fioParts) >= 3) {
            $lastName = $fioParts[0];
            $firstName = $fioParts[1];
            $middleName = $fioParts[2];
            
            // Добавляем окончания для родительного падежа
            $lastNameGenitive = $this->makeGenitive($lastName);
            $firstNameGenitive = $this->makeGenitive($firstName);
            $middleNameGenitive = $this->makeGenitive($middleName);
            
            $fioGenitive = "{$lastNameGenitive} {$firstNameGenitive} {$middleNameGenitive}";
        } else {
            $fioGenitive = $fio; // Если ФИО не полное, оставляем как есть
        }
        
        $finalText = "В вашем кабинете был {$resultText} тест \"{$test}\" респондентом {$fioGenitive} с номером сессии {$sessionNumber} ";

        // Ищем Telegram-пользователя по логину
        $recipient = TelegramUser::where('user_login', $login)->first();
        if (!$recipient) {
            return response()->json([
                'status' => 'error',
                'message' => 'Пользователь с таким логином не найден',
            ], 404);
        }

        // Проверяем настройки уведомлений пользователя
        $chatDisabled = (bool)($recipient->chat_disabled ?? false);
        if ($chatDisabled) {
            Log::info('Direct notification skipped: notifications are disabled for user', [
                'login' => $login,
                'user_id' => $recipient->user_id,
                'telegram_id' => $recipient->telegram_id,
                'chat_disabled' => $chatDisabled,
            ]);

            // Still create a record for audit trail (non-broadcast)
            $notification = Notification::create([
                'description' => $finalText,
                'is_broadcast' => false,
            ]);

            // Queue this direct notification specifically for this user (sent_at = null)
            DB::table('notification_history')->updateOrInsert([
                'telegram_id' => $recipient->telegram_id,
                'notification_id' => $notification->id,
            ], [
                'sent_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 'skipped',
                'reason' => 'notifications_disabled',
                'id' => $notification->id,
            ]);
        }

        // Подготавливаем Telegram-клиент
        $guzzle = new GuzzleClient([
            'verify' => config('app.env') === 'local' ? false : true,
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
        ]);
        $httpClient = new GuzzleHttpClient($guzzle);
        $telegram = new Api(
            config('services.telegram.bot_token'),
            false,
            $httpClient
        );

        try {
            $telegram->sendMessage([
                'chat_id' => $recipient->telegram_id,
                'text' => "🔔 {$finalText}"
            ]);

            // Сохраняем Notification для журнала
            $notification = Notification::create([
                'description' => $finalText,
                'is_broadcast' => false,
            ]);

            // Записываем отправку в notification_history
            DB::table('notification_history')->insert([
                'telegram_id' => $recipient->telegram_id,
                'notification_id' => $notification->id,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Direct notification sent to user', [
                'login' => $login,
                'telegram_id' => $recipient->telegram_id,
                'notification_id' => $notification->id,
            ]);

            return response()->json([
                'status' => 'ok',
                'id' => $notification->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send direct notification', [
                'login' => $login,
                'telegram_id' => $recipient->telegram_id ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Не удалось отправить сообщение пользователю',
            ], 500);
        }
    }

}
