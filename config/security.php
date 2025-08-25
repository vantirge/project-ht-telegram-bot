<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains security-related configuration options for the application.
    |
    */

    // Rate limiting settings
    'rate_limiting' => [
        'enabled' => env('SECURITY_RATE_LIMITING', true),
        'max_attempts' => env('SECURITY_MAX_ATTEMPTS', 60),
        'decay_minutes' => env('SECURITY_DECAY_MINUTES', 1),
    ],

    // Brute force protection
    'brute_force' => [
        'enabled' => env('SECURITY_BRUTE_FORCE_PROTECTION', true),
        'max_auth_attempts' => env('SECURITY_MAX_AUTH_ATTEMPTS', 5),
        'max_code_attempts' => env('SECURITY_MAX_CODE_ATTEMPTS', 3),
        'lockout_minutes' => env('SECURITY_LOCKOUT_MINUTES', 5),
    ],

    // Input validation
    'input_validation' => [
        'enabled' => env('SECURITY_INPUT_VALIDATION', true),
        'max_login_length' => env('SECURITY_MAX_LOGIN_LENGTH', 50),
        'max_text_length' => env('SECURITY_MAX_TEXT_LENGTH', 1000),
        'max_code_length' => env('SECURITY_MAX_CODE_LENGTH', 6),
    ],

    // SQL injection protection
    'sql_injection' => [
        'enabled' => env('SECURITY_SQL_INJECTION_PROTECTION', true),
        'blocked_patterns' => [
            '/\b(union|select|insert|update|delete|drop|create|alter|exec|execute|declare|cast|convert)\b/i',
            '/[\'";\\\]/',
            '/--/',
            '/\/\*.*?\*\//s',
        ],
    ],

    // XSS protection
    'xss' => [
        'enabled' => env('SECURITY_XSS_PROTECTION', true),
        'blocked_patterns' => [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/javascript:/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
        ],
    ],

    // Logging
    'logging' => [
        'enabled' => env('SECURITY_LOGGING', true),
        'log_suspicious_activity' => env('SECURITY_LOG_SUSPICIOUS', true),
        'log_failed_attempts' => env('SECURITY_LOG_FAILED_ATTEMPTS', true),
    ],

    // Telegram webhook validation
    'telegram' => [
        'validate_user_agent' => env('SECURITY_TELEGRAM_VALIDATE_UA', true),
        'validate_request_structure' => env('SECURITY_TELEGRAM_VALIDATE_STRUCTURE', true),
    ],
]; 