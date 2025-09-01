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
        // Здесь можно добавить обработку других типов обновлений (callback_query и т.д.)
    }

    /**
     * Обработка входящего сообщения
     */
    private function processMessage(array $message): void
    {
        $chatId = $this->extractChatId($message);
        $text = $this->extractAndValidateText($message);
        
        Log::info('Processing message', ['chat_id' => $chatId, 'text' => $text]);

        // Шаг 1: Обработка глобальных команд (доступны всем)
        if ($this->isGlobalCommand($text)) {
            $this->handleGlobalCommand($chatId, $text);
            return;
        }

        // Шаг 2: Проверка авторизации пользователя
        $telegramUser = $this->getTelegramUser($chatId);



        // Шаг 3: Обработка команд авторизованных пользователей
        if ($telegramUser) {
            if ($this->handleAuthorizedUserCommand($chatId, $text, $telegramUser)) {
                return;
            }
        }

        // Шаг 4: Обработка команд неавторизованных пользователей
        if ($this->handleUnauthorizedUserCommand($chatId, $text)) {
            return;
        }



        // Шаг 5: Обработка состояний аутентификации
        if ($this->handleAuthenticationState($chatId, $text)) {
            return;
        }

        // Шаг 6: Отправка приветственного сообщения
        $this->sendWelcomeMessage($chatId);
    }

    // --- Вспомогательные методы для processMessage ---

    /**
     * Извлекает chat_id из сообщения
     */
    private function extractChatId(array $message): int
    {
        return $message['chat']['id'];
    }

    /**
     * Извлекает и валидирует текст сообщения
     */
    private function extractAndValidateText(array $message): string
    {
        $text = trim($message['text'] ?? '');
        return SecurityService::validateInput($text, 'text');
    }

    /**
     * Проверяет, является ли команда глобальной (доступна всем)
     */
    private function isGlobalCommand(string $text): bool
    {
        return $text === '/start';
    }

    /**
     * Обрабатывает глобальные команды
     */
    private function handleGlobalCommand(int $chatId, string $text): void
    {
        if ($text === '/start') {
            Log::info('Processing /start command');
            $this->handleStartCommand($chatId);
        }
    }

    /**
     * Получает пользователя Telegram по chat_id
     */
    private function getTelegramUser(int $chatId): ?TelegramUser
    {
        return TelegramUser::where('telegram_id', $chatId)->first();
    }

    /**
     * Обрабатывает команды авторизованных пользователей
     * Возвращает true, если команда была обработана
     */
    private function handleAuthorizedUserCommand(int $chatId, string $text, TelegramUser $telegramUser): bool
    {
        Log::info('User is authorized', ['chat_id' => $chatId]);

        // Обработка команд управления уведомлениями
        if (in_array($text, ['Отключить уведомления', 'Включить уведомления'])) {
            Log::info('Processing authorized command', ['text' => $text]);
            if ($text === 'Отключить уведомления') {
                $this->handleDisableCommand($chatId);
            } else {
                $this->handleEnableCommand($chatId);
            }
            return true;
        }

        // Обработка кнопки непрочитанных уведомлений
        if (str_starts_with($text, 'Непрочитанные уведомления')) {
            Log::info('Processing unread notifications command', ['text' => $text]);
            $this->handleUnreadNotificationsCommand($chatId, $telegramUser);
            return true;
        }

        // Игнорируем все остальные сообщения
        Log::info('Ignoring message from authorized user', ['text' => $text]);
        return false;
    }

    /**
     * Обрабатывает команды неавторизованных пользователей
     * Возвращает true, если команда была обработана
     */
    private function handleUnauthorizedUserCommand(int $chatId, string $text): bool
    {
        if ($text === '/disable') {
            $this->handleDisableCommand($chatId);
            return true;
        }

        if ($text === '/enable') {
            $this->handleEnableCommand($chatId);
            return true;
        }

        return false;
    }

    /**
     * Обрабатывает состояния аутентификации
     * Возвращает true, если состояние было обработано
     */
    private function handleAuthenticationState(int $chatId, string $text): bool
    {
        $cacheKey = $this->getAuthCacheKey($chatId);
        $state = Cache::get($cacheKey, ['stage' => null]);

        if ($state['stage'] === 'awaiting_login') {
            $this->handleLoginInput($chatId, $text);
            return true;
        }

        if ($state['stage'] === 'awaiting_code') {
            $this->handleCodeInput($chatId, $text, $state);
            return true;
        }

        return false;
    }

    /**
     * Отправляет приветственное сообщение
     */
    private function sendWelcomeMessage(int $chatId): void
    {
        $this->sendTelegramMessage($chatId, 'Добро пожаловать! Используйте команду /start для начала.');
    }

    // --- Обработчики команд и кнопок ---

    private function handleStartCommand(int $chatId): void
    {
        Cache::put($this->getAuthCacheKey($chatId), ['stage' => 'awaiting_login'], self::CACHE_TTL);
        $this->sendTelegramMessage($chatId, "Введите ваш логин в системе компании:");
    }



    /**
     * Отключает уведомления для текущего чата пользователя
     */
    private function handleDisableCommand(int $chatId): void
    {
        $telegramUser = TelegramUser::where('telegram_id', $chatId)->first();

        if (!$telegramUser) {
            $this->sendTelegramMessage($chatId, "Сначала авторизуйтесь.");
            return;
        }

        // Отключаем уведомления для текущего чата
        $telegramUser->update(['chat_disabled' => true]);

        $this->sendMainMenuKeyboard($chatId, 'Уведомления отключены.');
    }

    /**
     * Включает уведомления для текущего чата пользователя
     */
    private function handleEnableCommand(int $chatId): void
    {
        $telegramUser = TelegramUser::where('telegram_id', $chatId)->first();

        if (!$telegramUser) {
            $this->sendTelegramMessage($chatId, "Сначала авторизуйтесь.");
            return;
        }

        // Включаем уведомления для текущего чата
        $telegramUser->update(['chat_disabled' => false]);
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

        // Fallback: try exact match with common variations
        if (!$user) {
            $loginVariations = $this->generateLoginVariations($login);
            $user = User::whereIn('name', $loginVariations)->first();
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
            $lowerLogin = mb_strtolower($login, 'UTF-8');
            $user = User::whereRaw('LOWER(name) = ?', [$lowerLogin])->first();
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
     * Генерирует варианты логина для более точного поиска
     */
    private function generateLoginVariations(string $login): array
    {
        $variations = [
            $login,
            trim($login),
            str_replace(' ', '', $login),
            str_replace('-', '', $login),
            str_replace('_', '', $login),
            str_replace('.', '', $login),
            strtolower($login),
            strtoupper($login),
            ucfirst(strtolower($login)),
            ucwords(strtolower($login))
        ];

        // Добавляем варианты с заменой символов
        $variations[] = str_replace(['-', '_', '.'], '', $login);
        $variations[] = str_replace(['-', '_', '.', ' '], '', $login);
        
        // Добавляем варианты с пробелами
        if (strpos($login, ' ') === false) {
            // Если нет пробелов, добавляем варианты с пробелами
            $variations[] = str_replace(['-', '_'], ' ', $login);
        }

        // Удаляем дубликаты и пустые значения
        return array_unique(array_filter($variations));
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
            
            $keyboard = [];
            
            if ($telegramUser) {
                $chatDisabled = (bool)($telegramUser->chat_disabled ?? false);
                $buttonText = $chatDisabled ? 'Включить уведомления' : 'Отключить уведомления';
                $unreadCount = $this->getUnreadNotificationsCount($telegramUser);
                
                // Сначала добавляем кнопку управления уведомлениями
                $keyboard[] = [['text' => $buttonText]];
                
                // Показываем кнопку непрочитанных сообщений только если есть непрочитанные
                if ($unreadCount > 0) {
                    $unreadButton = 'Непрочитанные уведомления (' . $unreadCount . ')';
                    $keyboard[] = [['text' => $unreadButton]];
                }
            } else {
                // Для неавторизованных пользователей показываем только кнопку управления
                $keyboard[] = [['text' => $buttonText]];
            }

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode([
                    'keyboard' => $keyboard,
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
     * Validate Telegram webhook request
     */
    private function validateTelegramRequest(Request $request): bool
    {
        // Проверка структуры запроса
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

            // Получаем всех telegram пользователей с включенными уведомлениями
            $recipients = TelegramUser::where('chat_disabled', false)->get();

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