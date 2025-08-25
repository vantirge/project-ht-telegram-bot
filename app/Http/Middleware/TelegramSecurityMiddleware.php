<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class TelegramSecurityMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Rate limiting для Telegram (более мягкий)
        $key = 'telegram_security_' . ($request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, 120)) { // 120 requests per minute для Telegram
            Log::warning('Telegram rate limit exceeded', ['ip' => $request->ip()]);
            return response()->json(['status' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key);

        // Логируем только действительно подозрительную активность
        $this->logSuspiciousTelegramActivity($request);

        return $next($request);
    }

    /**
     * Log suspicious Telegram activities
     */
    private function logSuspiciousTelegramActivity(Request $request): void
    {
        $input = $request->all();
        $suspiciousPatterns = [
            // Только самые опасные паттерны для Telegram
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/javascript:/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
        ];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                foreach ($suspiciousPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        Log::warning('Suspicious Telegram input detected', [
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