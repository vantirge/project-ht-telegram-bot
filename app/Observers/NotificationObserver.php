<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\TelegramUser;

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
            // Отправляем только широковещательные уведомления; прямые пропускаем
            if (isset($notification->is_broadcast) && (int)$notification->is_broadcast === 0) {
                Log::info('Уведомление не является широковещательным, отправка наблюдателем пропущена', ['notification_id' => $notification->id]);
                return;
            }

            Log::info('Создано новое широковещательное уведомление, отправляем сразу пользователям с включенными уведомлениями', ['notification_id' => $notification->id]);

            // Получаем всех telegram пользователей
            $recipients = TelegramUser::all();

            foreach ($recipients as $telegramUser) {
                try {
                    // Проверяем только флаг чата
                    if ((bool)($telegramUser->chat_disabled ?? false)) {
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