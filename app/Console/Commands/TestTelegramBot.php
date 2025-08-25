<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\TelegramBotController;
use Illuminate\Http\Request;

class TestTelegramBot extends Command
{
    protected $signature = 'telegram:test {chat_id} {message}';
    protected $description = 'Test Telegram bot functionality';

    public function handle()
    {
        $chatId = $this->argument('chat_id');
        $message = $this->argument('message');

        $this->info("Testing Telegram bot with chat_id: {$chatId}, message: {$message}");

        // Создаем тестовый запрос
        $request = new Request();
        $request->merge([
            'update_id' => 123456789,
            'message' => [
                'message_id' => 1,
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'testuser'
                ],
                'chat' => [
                    'id' => $chatId,
                    'first_name' => 'Test',
                    'type' => 'private'
                ],
                'date' => time(),
                'text' => $message
            ]
        ]);

        try {
            $controller = new TelegramBotController();
            $response = $controller->webhook($request);
            
            $this->info("Response status: " . $response->getStatusCode());
            $this->info("Response content: " . $response->getContent());
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
        }
    }
} 