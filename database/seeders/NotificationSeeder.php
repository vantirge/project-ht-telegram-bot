<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Notification;

class NotificationSeeder extends Seeder
{
    public function run()
    {
        Notification::create([
            'description' => 'Добро пожаловать в наш Telegram бот!',
        ]);

        Notification::create([
            'description' => 'У вас есть новое сообщение от администратора',
        ]);

        Notification::create([
            'description' => 'Система обновлена до новой версии',
        ]);

        Notification::create([
            'description' => 'Напоминание: проверьте ваши настройки',
        ]);

        Notification::create([
            'description' => 'Спасибо за использование нашего сервиса!',
        ]);
    }
}