<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Models\TelegramUser;

use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class SendNotifications extends Command
{
    protected $signature = 'notifications:send';
    protected $description = 'Отправить все неотправленные уведомления пользователям';

    protected Api $telegram;

    public function __construct()
    {
        parent::__construct();
        
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

    public function handle()
    {
        try {
            // Получаем всех telegram пользователей с включенными уведомлениями
            $recipients = TelegramUser::where('chat_disabled', false)->get();

            // Получаем все уведомления, которые еще не отправлены
            $notifications = Notification::whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('notification_history')
                      ->whereRaw('notification_history.notification_id = notifications.id');
            })->get();

            if ($notifications->isEmpty()) {
                $this->info('Нет новых уведомлений для отправки.');
                return 0;
            }

            $sentCount = 0;
            foreach ($notifications as $notification) {
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

                        $sentCount++;
                        $this->info("Отправлено уведомление {$notification->id} пользователю {$telegramUser->telegram_id}");
                    } catch (\Exception $e) {
                        Log::error('Failed to send notification', [
                            'notification_id' => $notification->id,
                            'telegram_id' => $telegramUser->telegram_id,
                            'error' => $e->getMessage()
                        ]);
                        $this->error("Ошибка отправки уведомления {$notification->id} пользователю {$telegramUser->telegram_id}: {$e->getMessage()}");
                    }
                }
            }

            $this->info("Отправлено {$sentCount} уведомлений.");
            return 0;
        } catch (\Exception $e) {
            Log::error('Error in SendNotifications command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("Ошибка: {$e->getMessage()}");
            return 1;
        }
    }
} 