<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Models\TelegramUser;
use App\Models\NotificationDisable;
use Illuminate\Support\Facades\DB;

class SendNewNotifications extends Command
{
    protected $signature = 'notifications:send-new';
    protected $description = 'Send new notifications to all users except those who disabled them';

    public function handle()
    {
        // Добавим поле sent_at, если его нет, или ищем по истории
        $newNotifications = Notification::whereNull('sent_at')->get();
        if ($newNotifications->isEmpty()) {
            $this->info('No new notifications to send.');
            return 0;
        }
        $disabledUserIds = NotificationDisable::pluck('user_id')->toArray();
        $recipients = TelegramUser::whereNotIn('user_id', $disabledUserIds)->get();
        $bot = app(\App\Http\Controllers\TelegramBotController::class);
        foreach ($newNotifications as $notification) {
            foreach ($recipients as $tgUser) {
                $bot->sendTelegramMessage($tgUser->telegram_id, "🔔 {$notification->description}");
                DB::table('notification_history')->insert([
                    'telegram_id' => $tgUser->telegram_id,
                    'notification_id' => $notification->id,
                    'sent_at' => now()
                ]);
            }
            $notification->sent_at = now();
            $notification->save();
        }
        $this->info('Notifications sent.');
        return 0;
    }
} 