<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SecurityService
{
    /**
     * Валидирует и санитизирует пользовательский ввод
     */
    public static function validateInput(string $input, string $type = 'general'): string
    {
        // Удаляем опасные символы
        $input = self::removeDangerousChars($input);
        
        // Валидация с учётом типа
        switch ($type) {
            case 'login':
                return self::validateLogin($input);
            case 'code':
                return self::validateCode($input);
            case 'text':
                return self::validateText($input);
            default:
                return self::validateGeneral($input);
        }
    }

    /**
     * Удаляет опасные символы
     */
    private static function removeDangerousChars(string $input): string
    {
        // Шаблоны для SQL-инъекций
        $sqlPatterns = [
            '/\b(union|select|insert|update|delete|drop|create|alter|exec|execute|declare|cast|convert|char|nchar|varchar|nvarchar)\b/i',
            '/[\'";\\\]/', // Удаляем кавычки и точку с запятой
            '/--/', // Удаляем SQL-комментарии
            '/\/\*.*?\*\//s', // Удаляем блочные SQL-комментарии
            '/xp_/', // Удаляем расширенные хранимые процедуры
            '/sp_/', // Удаляем хранимые процедуры
        ];

        $input = preg_replace($sqlPatterns, '', $input);
        
        // Шаблоны для XSS
        $xssPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/javascript:/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/<applet/i',
            '/<meta/i',
            '/<link/i',
        ];

        $input = preg_replace($xssPatterns, '', $input);
        
        // Удаляем нулевые байты и управляющие символы
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        return trim($input);
    }

    /**
     * Валидирует логин
     */
    private static function validateLogin(string $input): string
    {
        // Разрешаем буквы/цифры Юникод, пробелы, точки, дефисы и подчёркивания
        // Сохраняет имена на кириллице и с пробелами, например: "Алексей Козлов"
        $input = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $input);

        // Схлопываем множественные пробелы и обрезаем края
        $input = preg_replace('/\s+/u', ' ', $input);
        $input = trim($input);

        // Ограничиваем длину (корректно для мультибайта)
        if (mb_strlen($input, 'UTF-8') > 50) {
            $input = mb_substr($input, 0, 50, 'UTF-8');
        }

        return $input;
    }

    /**
     * Валидирует код подтверждения
     */
    private static function validateCode(string $input): string
    {
        // Разрешаем только цифры
        $input = preg_replace('/[^0-9]/', '', $input);
        
        // Ограничиваем длину 6 символами
        if (strlen($input) > 6) {
            $input = substr($input, 0, 6);
        }
        
        return $input;
    }

    /**
     * Валидирует текст
     */
    private static function validateText(string $input): string
    {
        // Для Telegram не применяем HTML-кодирование, чтобы не нарушить работу бота
        // Только ограничиваем длину и удаляем опасные символы
        
        // Удаляем нулевые байты и управляющие символы
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        // Ограничиваем длину
        if (strlen($input) > 1000) {
            $input = substr($input, 0, 1000);
        }
        
        return trim($input);
    }

    /**
     * Валидирует общий ввод
     */
    private static function validateGeneral(string $input): string
    {
        // Базовая санитизация
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        // Ограничиваем длину
        if (strlen($input) > 500) {
            $input = substr($input, 0, 500);
        }
        
        return $input;
    }

    /**
     * Проверяет попытки брутфорса
     */
    public static function checkBruteForce(string $identifier, string $type = 'auth'): bool
    {
        $key = "brute_force_{$type}_{$identifier}";
        $attempts = Cache::get($key, 0);
        
        $maxAttempts = match($type) {
            'auth' => 5,
            'code' => 3,
            default => 10
        };
        
        if ($attempts >= $maxAttempts) {
            Log::warning('Brute force attempt detected', [
                'identifier' => $identifier,
                'type' => $type,
                'attempts' => $attempts
            ]);
            return true;
        }
        
        return false;
    }

    /**
     * Увеличивает счётчик брутфорса
     */
    public static function incrementBruteForce(string $identifier, string $type = 'auth'): void
    {
        $key = "brute_force_{$type}_{$identifier}";
        $attempts = Cache::get($key, 0);
        Cache::put($key, $attempts + 1, 300); // 5 minutes
    }

    /**
     * Сбрасывает счётчик брутфорса
     */
    public static function resetBruteForce(string $identifier, string $type = 'auth'): void
    {
        $key = "brute_force_{$type}_{$identifier}";
        Cache::forget($key);
    }

    /**
     * Генерирует безопасную случайную строку
     */
    public static function generateSecureString(int $length = 32): string
    {
        return Str::random($length);
    }

    /**
     * Логирует событие безопасности
     */
    public static function logSecurityEvent(string $event, array $data = []): void
    {
        Log::warning("Security event: {$event}", array_merge($data, [
            'timestamp' => now(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]));
    }
} 