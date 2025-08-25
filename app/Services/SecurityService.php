<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SecurityService
{
    /**
     * Validate and sanitize user input
     */
    public static function validateInput(string $input, string $type = 'general'): string
    {
        // Remove dangerous characters
        $input = self::removeDangerousChars($input);
        
        // Type-specific validation
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
     * Remove dangerous characters
     */
    private static function removeDangerousChars(string $input): string
    {
        // SQL injection patterns
        $sqlPatterns = [
            '/\b(union|select|insert|update|delete|drop|create|alter|exec|execute|declare|cast|convert|char|nchar|varchar|nvarchar)\b/i',
            '/[\'";\\\]/', // Remove quotes and semicolons
            '/--/', // Remove SQL comments
            '/\/\*.*?\*\//s', // Remove SQL block comments
            '/xp_/', // Remove extended stored procedures
            '/sp_/', // Remove stored procedures
        ];

        $input = preg_replace($sqlPatterns, '', $input);
        
        // XSS patterns
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
        
        // Remove null bytes and control characters
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        return trim($input);
    }

    /**
     * Validate login input
     */
    private static function validateLogin(string $input): string
    {
        // Allow Unicode letters/numbers, spaces, dots, hyphens, and underscores
        // This preserves names with Cyrillic and spaces like "Алексей Козлов"
        $input = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $input);

        // Collapse multiple spaces and trim
        $input = preg_replace('/\s+/u', ' ', $input);
        $input = trim($input);

        // Limit length (multibyte-safe)
        if (mb_strlen($input, 'UTF-8') > 50) {
            $input = mb_substr($input, 0, 50, 'UTF-8');
        }

        return $input;
    }

    /**
     * Validate code input
     */
    private static function validateCode(string $input): string
    {
        // Only allow digits
        $input = preg_replace('/[^0-9]/', '', $input);
        
        // Limit length to 6 digits
        if (strlen($input) > 6) {
            $input = substr($input, 0, 6);
        }
        
        return $input;
    }

    /**
     * Validate text input
     */
    private static function validateText(string $input): string
    {
        // Для Telegram не применяем HTML-кодирование, так как это может нарушить работу бота
        // Только ограничиваем длину и удаляем опасные символы
        
        // Remove null bytes and control characters
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        // Limit length
        if (strlen($input) > 1000) {
            $input = substr($input, 0, 1000);
        }
        
        return trim($input);
    }

    /**
     * Validate general input
     */
    private static function validateGeneral(string $input): string
    {
        // Basic sanitization
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        // Limit length
        if (strlen($input) > 500) {
            $input = substr($input, 0, 500);
        }
        
        return $input;
    }

    /**
     * Check for brute force attempts
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
     * Increment brute force counter
     */
    public static function incrementBruteForce(string $identifier, string $type = 'auth'): void
    {
        $key = "brute_force_{$type}_{$identifier}";
        $attempts = Cache::get($key, 0);
        Cache::put($key, $attempts + 1, 300); // 5 minutes
    }

    /**
     * Reset brute force counter
     */
    public static function resetBruteForce(string $identifier, string $type = 'auth'): void
    {
        $key = "brute_force_{$type}_{$identifier}";
        Cache::forget($key);
    }

    /**
     * Generate secure random string
     */
    public static function generateSecureString(int $length = 32): string
    {
        return Str::random($length);
    }

    /**
     * Log security event
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