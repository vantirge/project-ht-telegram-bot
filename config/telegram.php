<?php
  file_put_contents(storage_path('logs/telegram_config.log'), 'config loaded: '.date('c').PHP_EOL, FILE_APPEND);
return [
    'default' => 'common',
    'bots' => [
        'common' => [
            'username'  => 'MyTelegramBot',
            'token' => env('TELEGRAM_BOT_TOKEN', '7950586264:AAHIXjrrzdWV-JDDRhyrqM7S_lKPT6ZylI0'),
            'commands' => [
                // Acme\Project\Commands\MyTelegramBot\BotCommand::class
            ],
        ],
    ],
    'async_requests' => env('TELEGRAM_ASYNC_REQUESTS', false),
    // Временно отключаем проверку SSL для туннеля (tuna, ngrok и т.д.)
    'http_client_handler' => 'guzzle',
    'guzzle_options' => [
        'verify' => false,
    ],
    'resolve_command_dependencies' => true,
    'commands' => [
        Telegram\Bot\Commands\HelpCommand::class,
    ],
    'command_groups' => [
        // Примеры см. выше
    ],
    'shared_commands' => [
        // 'start' => Acme\Project\Commands\StartCommand::class,
    ],
];











