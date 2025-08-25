<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;

class TestNotification extends Command
{
    protected $signature = 'notification:test {text : Текст уведомления}';
    protected $description = 'Создать тестовое уведомление';

    public function handle()
    {
        $text = $this->argument('text');
        
        try {
            $notification = Notification::create([
                'description' => $text
            ]);
            
            $this->info("Уведомление создано с ID: {$notification->id}");
            $this->info("Текст: {$text}");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Ошибка создания уведомления: {$e->getMessage()}");
            return 1;
        }
    }
} 