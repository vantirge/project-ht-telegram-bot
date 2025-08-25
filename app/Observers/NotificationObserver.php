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
            Log::info('New notification created, sending to users', ['notification_id' => $notification->id]);

            // Получаем ID пользователей, отключивших уведомления
            $disabledUserIds = NotificationDisable::pluck('user_id')->toArray();

            // Получаем всех telegram пользователей, кроме отключивших
            $recipients = TelegramUser::whereNotIn('user_id', $disabledUserIds)->get();

            foreach ($recipients as $telegramUser) {
                try {
                    $this->telegram->sendMessage([
                        'chat_id' => $telegramUser->telegram_id,
                        'text' => "🔔 {$notification->description}"
                    ]);

                    // Записываем в историю отправки
                    DB::table('notification_history')->insert([
                        'telegram_id' => $telegramUser->telegram_id,
                        'notification_id' => $notification->id,
                        'sent_at' => now()
                    ]);

                    Log::info('Notification sent successfully', [
                        'notification_id' => $notification->id,
                        'telegram_id' => $telegramUser->telegram_id
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send notification', [
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