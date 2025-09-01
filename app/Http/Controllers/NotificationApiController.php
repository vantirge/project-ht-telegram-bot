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
        // Normalize synonyms before validation
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

        // Validate: supports three modes
        // 1) Broadcast: message only
        // 2) Direct custom: login + message
        // 3) Structured direct: login + test + session_number + fio (+ result)
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

        // Mode 1: Broadcast (message without login)
        if (!empty($data['message']) && empty($data['login'])) {
            $finalText = SecurityService::validateInput($data['message'], 'text');
            // Broadcast via Notification model (Observer handles sending to everyone)
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

        // Mode 2: Direct custom message (login + message)
        if (!empty($data['message']) && !empty($data['login'])) {
            $login = SecurityService::validateInput($data['login'] ?? '', 'login');
            $finalText = SecurityService::validateInput($data['message'] ?? '', 'text');

            // Find telegram user by login
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

            // Prepare Telegram client
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

        // Mode 3: Structured payload: send ONLY to specific login
        $login = SecurityService::validateInput($data['login'] ?? '', 'login');
        $test = SecurityService::validateInput($data['test'] ?? '', 'text');
        $sessionNumber = SecurityService::validateInput($data['session_number'] ?? '', 'text');
        $fio = SecurityService::validateInput($data['fio'] ?? '', 'text');
        $result = isset($data['result']) && $data['result'] !== null
            ? SecurityService::validateInput($data['result'], 'text')
            : null;

        // Формируем сообщение в новом формате
        $resultText = !empty($result) ? $result : 'начат';
        
        // Если result пустой, используем "начат" как значение по умолчанию
        if (empty($result)) {
            $resultText = 'начат';
        }
        
        // Преобразуем ФИО в нужный падеж (родительный падеж)
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
        
        $finalText = "В вашем кабинете был {$resultText} тест \"{$test}\" с номером сессии {$sessionNumber}, {$fioGenitive}";

        // Find telegram user by login
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

        // Prepare Telegram client
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

            // Save Notification row for record keeping (optional but useful)
            $notification = Notification::create([
                'description' => $finalText,
                'is_broadcast' => false,
            ]);

            // Log to notification_history
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

    /**
     * Преобразует имя в родительный падеж
     */
    private function makeGenitive(string $name): string
    {
        $name = trim($name);
        
        // Базовые правила для русских имен
        $rules = [
            // Мужские имена
            'а' => 'ы',    // Иван -> Ивана
            'й' => 'я',    // Андрей -> Андрея
            'ь' => 'я',    // Игорь -> Игоря
            'н' => 'на',   // Иван -> Ивана (если не заканчивается на а)
            'р' => 'ра',   // Пётр -> Петра
            'л' => 'ла',   // Михаил -> Михаила
            'т' => 'та',   // Артём -> Артёма
            'с' => 'са',   // Денис -> Дениса
            'в' => 'ва',   // Владислав -> Владислава
            'д' => 'да',   // Владимир -> Владимира
            'м' => 'ма',   // Максим -> Максима
            'г' => 'га',   // Сергей -> Сергея
            'к' => 'ка',   // Николай -> Николая
            'х' => 'ха',   // Алексей -> Алексея
            'ш' => 'ша',   // Павел -> Павла
            'щ' => 'ща',   // Илья -> Ильи
            'з' => 'за',   // Борис -> Бориса
            'ж' => 'жа',   // Виктор -> Виктора
            'б' => 'ба',   // Роберт -> Роберта
            'п' => 'па',   // Филипп -> Филиппа
            'ф' => 'фа',   // Александр -> Александра
            'ц' => 'ца',   // Евгений -> Евгения
            'ч' => 'ча',   // Олег -> Олега
            'э' => 'а',    // Эдуард -> Эдуарда
            'ю' => 'я',    // Юрий -> Юрия
            'я' => 'и',    // Ярослав -> Ярослава
        ];
        
        // Женские имена
        $femaleRules = [
            'а' => 'ы',    // Мария -> Марии
            'я' => 'и',    // Анастасия -> Анастасии
            'ь' => 'и',    // Любовь -> Любови
            'й' => 'и',    // Наталья -> Натальи
        ];
        
        $lastChar = mb_strtolower(mb_substr($name, -1, 1, 'UTF-8'));
        
        // Проверяем мужские правила
        if (isset($rules[$lastChar])) {
            return mb_substr($name, 0, -1, 'UTF-8') . $rules[$lastChar];
        }
        
        // Проверяем женские правила
        if (isset($femaleRules[$lastChar])) {
            return mb_substr($name, 0, -1, 'UTF-8') . $femaleRules[$lastChar];
        }
        
        // Если правило не найдено, добавляем стандартное окончание
        if (mb_strlen($name, 'UTF-8') > 2) {
            $secondLastChar = mb_strtolower(mb_substr($name, -2, 1, 'UTF-8'));
            
            // Для имен, заканчивающихся на согласную
            if (!in_array($secondLastChar, ['а', 'е', 'ё', 'и', 'о', 'у', 'ы', 'э', 'ю', 'я'])) {
                return $name . 'а';
            }
        }
        
        // Если ничего не подошло, возвращаем как есть
        return $name;
    }
}

 