<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;
use App\Http\Controllers\NotificationApiController;
use App\Http\Middleware\TelegramSecurityMiddleware;

Route::post('/telegram/webhook', [TelegramBotController::class, 'webhook'])
    ->withoutMiddleware('throttle:api');
// ->middleware(TelegramSecurityMiddleware::class); // Временно отключено для тестирования

// External API to create notification and trigger broadcast via Observer
Route::post('/notifications', [NotificationApiController::class, 'store'])
    ->middleware('security');