<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\TelegramUser;
use App\Models\NotificationDisable;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class NotificationObserver
{
    protected Api $telegram;

    public function __construct()
    {
        $guzzle = new GuzzleClient([
            'verify' => config('app.env') === 'local' ? false : true,
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
        ]);
        $httpClient = new GuzzleHttpClient($guzzle);
        $this->telegram = new Api(
            config('services.telegram.bot_token'),
            false,
            $httpClient
        );
    }

    public function created(Notification $notification)
    {
        try {
            // Send only broadcast notifications; skip direct notifications
            if (isset($notification->is_broadcast) && (int)$notification->is_broadcast === 0) {
                Log::info('Notification is not broadcast, skipping observer send', ['notification_id' => $notification->id]);
                return;
            }

            Log::info('New broadcast notification created, sending immediately to enabled users', ['notification_id' => $notification->id]);

            // Получаем ID пользователей, отключивших уведомления
            $disabledUserIds = NotificationDisable::pluck('user_id')->toArray();

            // Получаем всех telegram пользователей
            $recipients = TelegramUser::all();

            foreach ($recipients as $telegramUser) {
                try {
                    $isDisabled = in_array($telegramUser->user_id, $disabledUserIds);
                    if ($isDisabled || (bool)($telegramUser->chat_disabled ?? false)) {
                        // Пользователь с отключёнными уведомлениями: просто появится в "непрочитанных"
                        // Ничего не делаем здесь
                        continue;
                    }

                    $this->telegram->sendMessage([
                        'chat_id' => $telegramUser->telegram_id,
                        'text' => "🔔 {$notification->description}"
                    ]);

                    DB::table('notification_history')->updateOrInsert([
                        'telegram_id' => $telegramUser->telegram_id,
                        'notification_id' => $notification->id,
                    ], [
                        'sent_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send broadcast notification', [
                        'notification_id' => $notification->id,
                        'telegram_id' => $telegramUser->telegram_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in NotificationObserver', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
        }
    }
} 