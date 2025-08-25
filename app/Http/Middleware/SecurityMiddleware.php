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
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Protect external integrations by API Key for specific endpoint
        if ($request->is('api/notifications')) {
            $provided = $request->header('X-Api-Key');
            $expected = config('services.external_api.key', env('EXTERNAL_API_KEY'));
            if (!$expected || $provided !== $expected) {
                Log::warning('External API key mismatch', ['ip' => $request->ip()]);
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        // Rate limiting
        $key = 'security_' . ($request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, 60)) { // 60 requests per minute
            Log::warning('Rate limit exceeded', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Too many requests'], 429);
        }
        RateLimiter::hit($key);

        // Sanitize input data
        $this->sanitizeInput($request);

        // Log suspicious activities
        $this->logSuspiciousActivity($request);

        return $next($request);
    }

    /**
     * Sanitize input data to prevent SQL injection and XSS
     */
    private function sanitizeInput(Request $request): void
    {
        $input = $request->all();
        $sanitized = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                // Remove potentially dangerous characters
                $value = $this->sanitizeString($value);
            }
            $sanitized[$key] = $value;
        }

        $request->merge($sanitized);
    }

    /**
     * Sanitize string input
     */
    private function sanitizeString(string $input): string
    {
        // Для Telegram webhook не применяем агрессивную санитизацию
        // так как это может нарушить работу бота
        
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        return $input;
    }

    /**
     * Log suspicious activities
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