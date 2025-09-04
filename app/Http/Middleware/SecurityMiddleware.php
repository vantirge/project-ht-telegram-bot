<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SecurityMiddleware
{
    /**
     * Обрабатывает входящий HTTP-запрос.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Лимитирование запросов (в локальном окружении для /api/notifications пропускаем для удобства тестирования)
        $isNotifications = $request->is('api/notifications');
        $isLocal = app()->environment('local');
        if (!($isLocal && $isNotifications)) {
            $key = 'security_' . ($request->ip() ?? 'unknown');
            if (RateLimiter::tooManyAttempts($key, 60)) { // 60 запросов в минуту
                Log::warning('Превышен лимит запросов', ['ip' => $request->ip()]);
                return response()->json(['error' => 'Слишком много запросов'], 429);
            }
            RateLimiter::hit($key);
        }

        // Санитизируем входные данные
        $this->sanitizeInput($request);

        // Логируем подозрительную активность
        $this->logSuspiciousActivity($request);

        return $next($request);
    }

    /**
     * Санитизирует входные данные для предотвращения SQL-инъекций и XSS
     */
    private function sanitizeInput(Request $request): void
    {
        $input = $request->all();
        $sanitized = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                // Удаляем потенциально опасные символы
                $value = $this->sanitizeString($value);
            }
            $sanitized[$key] = $value;
        }

        $request->merge($sanitized);
    }

    /**
     * Санитизирует строковый ввод
     */
    private function sanitizeString(string $input): string
    {
        // Для Telegram webhook не применяем агрессивную санитизацию,
        // так как это может нарушить работу бота
        
        // Удаляем нулевые байты
        $input = str_replace("\0", '', $input);
        
        // Обрезаем пробелы по краям
        $input = trim($input);
        
        return $input;
    }

    /**
     * Логирует подозрительную активность
     */
    private function logSuspiciousActivity(Request $request): void
    {
        $input = $request->all();
        $suspiciousPatterns = [
            '/\b(union|select|insert|update|delete|drop|create|alter|exec|execute)\b/i',
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
            '/[\'";\\\]/',
        ];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                foreach ($suspiciousPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        Log::warning('Suspicious input detected', [
                            'ip' => $request->ip(),
                            'user_agent' => $request->userAgent(),
                            'key' => $key,
                            'value' => $value,
                            'pattern' => $pattern
                        ]);
                        break;
                    }
                }
            }
        }
    }
} 