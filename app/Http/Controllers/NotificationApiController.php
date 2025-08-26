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
use App\Models\NotificationDisable;

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

            // Respect user-level notification settings
            $isDisabled = NotificationDisable::where('user_id', $recipient->user_id)->exists();
            $chatDisabled = (bool)($recipient->chat_disabled ?? false);
            if ($isDisabled || $chatDisabled) {
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

        $lines = [
            "Тест: {$test}",
            "Номер сессии: {$sessionNumber}",
            "ФИО: {$fio}",
        ];
        if (!empty($result)) {
            $lines[] = "Результат: {$result}";
        }
        $finalText = implode("\n", $lines);

        // Find telegram user by login
        $recipient = TelegramUser::where('user_login', $login)->first();
        if (!$recipient) {
            return response()->json([
                'status' => 'error',
                'message' => 'Пользователь с таким логином не найден',
            ], 404);
        }

        // Respect user-level notification settings
        $isDisabled = NotificationDisable::where('user_id', $recipient->user_id)->exists();
        $chatDisabled = (bool)($recipient->chat_disabled ?? false);
        if ($isDisabled || $chatDisabled) {
            Log::info('Direct notification skipped: notifications are disabled for user', [
                'login' => $login,
                'user_id' => $recipient->user_id,
                'telegram_id' => $recipient->telegram_id,
                'db_disabled' => $isDisabled,
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
}

 