<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Telegram\Bot\Api;
use App\Models\User;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Mail;
use App\Mail\AuthCodeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client as GuzzleClient;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use App\Models\NotificationDisable;
use App\Models\Notification;
use App\Services\SecurityService;
use Throwable;

class TelegramBotController extends Controller
{
    protected Api $telegram;
    private const CACHE_TTL = 600; // 10 minutes
    private const AUTH_CODE_LENGTH = 6;
    private const AUTH_CODE_MIN = 100000;
    private const AUTH_CODE_MAX = 999999;

    public function __construct()
    {
        // В production лучше включить проверку SSL
        $guzzle = new GuzzleClient([
            'verify' => config('app.env') === 'local' ? false : true, // Отключаем только локально
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
        ]);
        $httpClient = new GuzzleHttpClient($guzzle);
        $this->telegram = new Api(
            config('services.telegram.bot_token'), // Используем config вместо env напрямую
            false,
            $httpClient
        );
    }

    /**
     * Обработчик вебхука от Telegram
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            Log::info('Telegram webhook received', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'data' => $request->all()
            ]);

            // Security validation
            if (!$this->validateTelegramRequest($request)) {
                SecurityService::logSecurityEvent('Invalid Telegram request', [
                    'ip' => $request->ip(),
                    'data' => $request->all()
                ]);
                return response()->json(['status' => 'unauthorized'], 401);
            }

            $this->handle($request);
            return response()->json(['status' => 'ok']);
        } catch (Throwable $e) {
            Log::error('Error processing Telegram webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->all()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Основная логика обработки сообщений
     */
    public function handle(Request $request): void
    {
        $update = $request->all();

        if (isset($update['message'])) {
            $this->processMessage($update['message']);
        }
        
    }

    /**
     * Обработка входящего сообщения
     */
    private function processMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        // Security validation
        $text = SecurityService::validateInput($text, 'text');
        
        Log::info('Processing message', ['chat_id' => $chatId, 'text' => $text]);

        // Проверяем, авторизован ли пользователь
        $telegramUser = TelegramUser::where('telegram_id', $chatId)->first();
        
        // Обработка команд (разрешаем для всех пользователей)
        if ($text === '/start') {
            Log::info('Processing /start command');
            $this->handleStartCommand($chatId);
            return;
        }



        if ($telegramUser) {
            Log::info('User is authorized', ['chat_id' => $chatId]);
            // Если пользователь авторизован, обрабатываем только разрешенные команды
            if (in_array($text, ['Отключить уведомления', 'Включить уведомления'])) {
                Log::info('Processing authorized command', ['text' => $text]);
                if ($text === 'Отключить уведомления') {
                    $this->handleDisableCommand($chatId);
                } else {
                    $this->handleEnableCommand($chatId);
                }
                return;
            }
            // Кнопка непрочитанных уведомлений
            if (str_starts_with($text, 'Непрочитанные уведомления')) {
                $this->handleUnreadNotificationsCommand($chatId, $telegramUser);
                return;
            }
            // Игнорируем все остальные сообщения
            Log::info('Ignoring message from authorized user', ['text' => $text]);
            return;
        }

        // Обработка команд для неавторизованных пользователей
        if ($text === '/disable') {
            $this->handleDisableCommand($chatId);
            return;
        }

        if ($text === '/enable') {
            $this->handleEnableCommand($chatId);
            return;
        }



        // Обработка состояний аутентификации
        $cacheKey = $this->getAuthCacheKey($chatId);
        $state = Cache::get($cacheKey, ['stage' => null]);

        if ($state['stage'] === 'awaiting_login') {
            $this->handleLoginInput($chatId, $text);
            return;
        }

        if ($state['stage'] === 'awaiting_code') {
            $this->handleCodeInput($chatId, $text, $state);
            return;
        }

        // Если ни одно условие не сработало, отправляем приветствие
        $this->sendTelegramMessage($chatId, 'Добро пожаловать! Используйте команду /start для начала.');
    }

    // --- Обработчики команд и кнопок ---

    private function handleStartCommand(int $chatId): void
    {
        Cache::put($this->getAuthCacheKey($chatId), ['stage' => 'awaiting_login'], self::CACHE_TTL);
        $this->sendTelegramMessage($chatId, "Введите ваш логин в системе компании:");
    }



    private function handleDisableCommand(int $chatId): void
    {
        $telegramUser = TelegramUser::where('telegram_id', $chatId)->first();

        if (!$telegramUser) {
            $this->sendTelegramMessage($chatId, "Сначала авторизуйтесь.");
            return;
        }

        NotificationDisable::updateOrCreate(
            ['user_id' => $telegramUser->user_id],
            ['disabled_at' => now()]
        );

        $this->sendMainMenuKeyboard($chatId, 'Уведомления отключены.');
    }

    private function handleEnableCommand(int $chatId): void
    {
        $telegramUser = TelegramUser::where('telegram_id', $chatId)->first();

        if (!$telegramUser) {
            $this->sendTelegramMessage($chatId, "Сначала авторизуйтесь.");
            return;
        }

        // Включаем уведомления
        NotificationDisable::where('user_id', $telegramUser->user_id)->delete();
        $this->sendMainMenuKeyboard($chatId, 'Уведомления включены.');
    }



    // --- Обработчики состояний аутентификации ---

    private function handleLoginInput(int $chatId, string $login): void
    {
        // Security validation
        $login = SecurityService::validateInput($login, 'login');
        
        if (empty($login)) {
            $this->sendTelegramMessage($chatId, "Логин не может быть пустым. Попробуйте снова.");
            return;
        }

        // Check for brute force
        if (SecurityService::checkBruteForce($chatId, 'auth')) {
            $this->sendTelegramMessage($chatId, "Слишком много попыток. Попробуйте позже.");
            return;
        }

        // Try to find user by exact name (case-insensitive)
        $lowerLogin = mb_strtolower($login, 'UTF-8');
        $user = User::whereRaw('LOWER(name) = ?', [$lowerLogin])->first();

        // Fallback: try to find via TelegramUser.user_login mapping
        if (!$user) {
            $map = TelegramUser::whereRaw('LOWER(user_login) = ?', [$lowerLogin])->first();
            if ($map) {
                $user = User::find($map->user_id);
            }
        }

        // Fallback: partial match
        if (!$user) {
            $user = User::where('name', 'like', "%{$login}%")->first();
        }

        if (!$user) {
            SecurityService::incrementBruteForce($chatId, 'auth');
            SecurityService::logSecurityEvent('Failed login attempt', [
                'chat_id' => $chatId,
                'login' => $login
            ]);
            $this->sendTelegramMessage($chatId, "Пользователь с таким именем не найден. Попробуйте снова.");
            return;
        }

        $cacheKey = $this->getAuthCacheKey($chatId);
        $currentState = Cache::get($cacheKey, []);

        // Если уже ожидается код для этого же логина, не генерируем новый
        if (($currentState['stage'] ?? null) === 'awaiting_code' &&
            isset($currentState['login']) &&
            strtolower($currentState['login']) === strtolower($login)) {
            $this->sendTelegramMessage($chatId, "На ваш email уже отправлен код подтверждения. Введите его:");
            return;
        }

        $code = $this->generateAuthCode();
        Cache::put($cacheKey, [
            'stage' => 'awaiting_code',
            'login' => $login,
            'code' => $code,
            'user_id' => $user->id // Сохраняем ID пользователя
        ], self::CACHE_TTL);

        try {
            Mail::to($user->email)->send(new AuthCodeMail($code));
            $this->sendTelegramMessage($chatId, "На ваш email отправлен код подтверждения. Введите его:");
        } catch (Throwable $e) {
            Log::error('Failed to send auth code email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            $this->sendTelegramMessage($chatId, "Ошибка отправки кода. Попробуйте позже.");
            Cache::forget($cacheKey); // Удаляем состояние при ошибке
        }
    }

    private function handleCodeInput(int $chatId, string $inputCode, array $state): void
    {
        // Security validation
        $inputCode = SecurityService::validateInput($inputCode, 'code');
        
        if (!isset($state['code']) || !isset($state['login'])) {
            $this->sendTelegramMessage($chatId, "Ошибка состояния. Пожалуйста, начните сначала.");
            Cache::forget($this->getAuthCacheKey($chatId));
            return;
        }

        // Check for brute force
        if (SecurityService::checkBruteForce($chatId, 'code')) {
            $this->sendTelegramMessage($chatId, "Слишком много попыток. Попробуйте позже.");
            return;
        }

        if (trim($inputCode) != $state['code']) {
            SecurityService::incrementBruteForce($chatId, 'code');
            SecurityService::logSecurityEvent('Failed code attempt', [
                'chat_id' => $chatId,
                'input_code' => $inputCode
            ]);
            $this->sendTelegramMessage($chatId, "Неверный код. Попробуйте ещё раз.");
            return;
        }

        $user = User::find($state['user_id'] ?? null);
        if (!$user) {
            // fallback на поиск по имени, если ID не сохранился
            $login = SecurityService::validateInput($state['login'], 'login');
            $user = User::where('name', 'like', $login)->first();
        }

        if (!$user) {
            $this->sendTelegramMessage($chatId, "Ошибка. Пользователь не найден.");
            Cache::forget($this->getAuthCacheKey($chatId));
            return;
        }

        // Создаем или обновляем связь с Telegram
        TelegramUser::updateOrCreate(
            ['telegram_id' => $chatId],
            ['user_id' => $user->id, 'user_login' => $state['login']]
        );

        Cache::forget($this->getAuthCacheKey($chatId));

        // Показываем кнопку управления уведомлениями
        $this->sendMainMenuKeyboard($chatId, 'Вы успешно авторизованы!');
    }

    // --- Вспомогательные методы ---

    private function getAuthCacheKey(int $chatId): string
    {
        return 'tg_auth_' . $chatId;
    }

    private function generateAuthCode(): int
    {
        return random_int(self::AUTH_CODE_MIN, self::AUTH_CODE_MAX);
    }

    private function sendTelegramMessage(int $chatId, string $text): void
    {
        try {
            Log::info('Sending Telegram message', ['chat_id' => $chatId, 'text' => $text]);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text
            ]);
            Log::info('Telegram message sent successfully', ['chat_id' => $chatId]);
        } catch (Throwable $e) {
            Log::error('Failed to send Telegram message', [
                'chat_id' => $chatId,
                'text' => $text,
                'error' => $e->getMessage()
            ]);
        }
    }



    private function sendMenuReplyKeyboard(int $chatId, string $text, string $buttonText): void
    {
        try {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => $buttonText]]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false,
                ])
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to send menu with reply keyboard', [
                'chat_id' => $chatId,
                'text' => $text,
                'error' => $e->getMessage()
            ]);
            // Отправляем сообщение без клавиатуры в случае ошибки
            $this->sendTelegramMessage($chatId, $text);
        }
    }

    private function sendMainMenuKeyboard(int $chatId, string $text): void
    {
        try {
            // Определяем текст кнопки в зависимости от статуса уведомлений
            $telegramUser = TelegramUser::where('telegram_id', $chatId)->first();
            $buttonText = 'Отключить уведомления';
            
            if ($telegramUser) {
                $isDisabled = NotificationDisable::where('user_id', $telegramUser->user_id)->exists();
                $buttonText = $isDisabled ? 'Включить уведомления' : 'Отключить уведомления';
                $unreadCount = $this->getUnreadNotificationsCount($telegramUser);
                $unreadButton = 'Непрочитанные уведомления (' . $unreadCount . ')';
            } else {
                $unreadButton = 'Непрочитанные уведомления (0)';
            }

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => $buttonText]],
                        [['text' => $unreadButton]]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false,
                ])
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to send main menu keyboard', [
                'chat_id' => $chatId,
                'text' => $text,
                'error' => $e->getMessage()
            ]);
            // Отправляем сообщение без клавиатуры в случае ошибки
            $this->sendTelegramMessage($chatId, $text);
        }
    }

    /**
     * Отправка пропущенных уведомлений пользователю
     */
    private function sendMissedNotifications(TelegramUser $telegramUser, int $chatId, ?string $lastDisabled): void
    {
        try {
            // Если пользователь никогда не отключал уведомления — берём дату создания записи TelegramUser
            if (!$lastDisabled) {
                $lastDisabled = $telegramUser->created_at;
            }

            // Получаем ID уже отправленных уведомлений этому пользователю
            $sentIds = DB::table('notification_history')
                ->where('telegram_id', $chatId)
                ->pluck('notification_id')
                ->toArray();

            // Получаем все уведомления, созданные после отключения/регистрации и ещё не отправленные
            $notifications = Notification::where('created_at', '>=', $lastDisabled)
                ->whereNotIn('id', $sentIds)
                ->get();

            foreach ($notifications as $notification) {
                $this->sendTelegramMessage($chatId, "🔔 {$notification->description}");

                DB::table('notification_history')->upsert([
                    'telegram_id' => $chatId,
                    'notification_id' => $notification->id,
                    'sent_at' => now()
                ], ['telegram_id', 'notification_id'], ['sent_at']);
            }
        } catch (Throwable $e) {
            Log::error('Error sending missed notifications', [
                'user_id' => $telegramUser->user_id,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Подсчет количества непрочитанных широковещательных уведомлений для пользователя
     */
    private function getUnreadNotificationsCount(TelegramUser $telegramUser): int
    {
        try {
            $chatId = $telegramUser->telegram_id;
            $sentIds = DB::table('notification_history')
                ->where('telegram_id', $chatId)
                ->whereNotNull('sent_at')
                ->pluck('notification_id')
                ->toArray();

            $broadcastMissed = Notification::whereNotIn('id', $sentIds)
                ->where(function ($q) {
                    if (\Schema::hasColumn('notifications', 'is_broadcast')) {
                        $q->where('is_broadcast', true);
                    }
                })
                ->count();

            $queuedDirects = DB::table('notification_history')
                ->where('telegram_id', $chatId)
                ->whereNull('sent_at')
                ->count();

            return $broadcastMissed + $queuedDirects;
        } catch (\Throwable $e) {
            Log::error('Failed to count unread notifications', [
                'user_id' => $telegramUser->user_id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Обработчик кнопки "Непрочитанные уведомления (N)"
     */
    private function handleUnreadNotificationsCommand(int $chatId, TelegramUser $telegramUser): void
    {
        try {
            $sentIds = DB::table('notification_history')
                ->where('telegram_id', $chatId)
                ->whereNotNull('sent_at')
                ->pluck('notification_id')
                ->toArray();

            // 1) Широковещательные, которых нет в истории (не отправлялись этому чату)
            $broadcasts = Notification::whereNotIn('id', $sentIds)
                ->where(function ($q) {
                    if (\Schema::hasColumn('notifications', 'is_broadcast')) {
                        $q->where('is_broadcast', true);
                    }
                })
                ->get();

            // 2) Очередь прямых сообщений для этого чата (sent_at IS NULL)
            $directQueued = DB::table('notification_history')
                ->where('telegram_id', $chatId)
                ->whereNull('sent_at')
                ->pluck('notification_id')
                ->toArray();

            $directNotifications = Notification::whereIn('id', $directQueued)->get();

            // Объединяем и сортируем по дате создания
            $all = $broadcasts->concat($directNotifications)->sortBy('created_at')->values();

            if ($all->isEmpty()) {
                $this->sendMainMenuKeyboard($chatId, 'Нет непрочитанных уведомлений.');
                return;
            }

            foreach ($all as $notification) {
                $createdAt = optional($notification->created_at)->format('d.m.Y H:i');
                $prefix = $createdAt ? '📅 ' . $createdAt . "\n" : '';
                $this->sendTelegramMessage($chatId, $prefix . '🔔 ' . $notification->description);

                // Если записи не было — создаём, если была (queued) — проставим sent_at
                DB::table('notification_history')->updateOrInsert([
                    'telegram_id' => $chatId,
                    'notification_id' => $notification->id,
                ], [
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->sendMainMenuKeyboard($chatId, 'Отправлены все непрочитанные уведомления.');
        } catch (\Throwable $e) {
            Log::error('Failed to send unread notifications', [
                'user_id' => $telegramUser->user_id,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            $this->sendTelegramMessage($chatId, 'Ошибка при отправке непрочитанных уведомлений. Попробуйте позже.');
        }
    }

    /**
     * Validate Telegram webhook request
     */
    private function validateTelegramRequest(Request $request): bool
    {
        // В production можно включить проверку User-Agent
        // $userAgent = $request->userAgent();
        // if (!$userAgent || !str_contains(strtolower($userAgent), 'telegram')) {
        //     return false;
        // }

        // Validate request structure
        $data = $request->all();
        if (!isset($data['update_id'])) {
            return false;
        }

        // Проверяем наличие сообщения или callback_query
        if (!isset($data['message']) && !isset($data['callback_query'])) {
            return false;
        }

        // Если есть сообщение, проверяем базовую структуру
        if (isset($data['message'])) {
            $message = $data['message'];
            if (!isset($message['chat']['id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Массовая рассылка уведомления всем пользователям, кроме отключивших уведомления
     * @param int $notificationId
     * @return void
     */
    public function broadcastNotification(int $notificationId): void
    {
        try {
            $notification = Notification::find($notificationId);
            if (!$notification) {
                Log::warning('Notification not found for broadcast', ['id' => $notificationId]);
                return;
            }

            // Получаем ID пользователей, отключивших уведомления
            $disabledUserIds = NotificationDisable::pluck('user_id')->toArray();

            // Получаем всех telegram пользователей, кроме отключивших
            $recipients = TelegramUser::whereNotIn('user_id', $disabledUserIds)->get();

            foreach ($recipients as $telegramUser) {
                // Проверяем, не отправляли ли мы это уведомление ранее
                $alreadySent = DB::table('notification_history')
                    ->where('telegram_id', $telegramUser->telegram_id)
                    ->where('notification_id', $notification->id)
                    ->exists();

                if (!$alreadySent) {
                    $this->sendTelegramMessage($telegramUser->telegram_id, "🔔 {$notification->description}");

                    DB::table('notification_history')->upsert([
                        'telegram_id' => $telegramUser->telegram_id,
                        'notification_id' => $notification->id,
                        'sent_at' => now()
                    ], ['telegram_id', 'notification_id'], ['sent_at']);
                }
            }
        } catch (Throwable $e) {
            Log::error('Error broadcasting notification', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
        }
    }
}