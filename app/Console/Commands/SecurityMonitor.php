<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SecurityMonitor extends Command
{
    protected $signature = 'security:monitor {--clean : Clean old security logs}';
    protected $description = 'Monitor security events and clean old logs';

    public function handle()
    {
        if ($this->option('clean')) {
            $this->cleanOldLogs();
            return;
        }

        $this->info('Security Monitor Report');
        $this->info('=====================');

        // Check brute force attempts
        $this->checkBruteForceAttempts();

        // Check suspicious activities
        $this->checkSuspiciousActivities();

        // Check rate limiting
        $this->checkRateLimiting();

        $this->info('Security monitoring completed.');
    }

    private function checkBruteForceAttempts()
    {
        $this->info("\nBrute Force Attempts:");
        
        $keys = Cache::get('brute_force_*');
        if (!$keys) {
            $this->warn('No brute force attempts detected.');
            return;
        }

        foreach ($keys as $key => $attempts) {
            $this->warn("Key: {$key}, Attempts: {$attempts}");
        }
    }

    private function checkSuspiciousActivities()
    {
        $this->info("\nSuspicious Activities (Last 24 hours):");
        
        // Check logs for suspicious activities
        $logFile = storage_path('logs/laravel.log');
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $suspiciousCount = substr_count($content, 'Suspicious input detected');
            $failedAuthCount = substr_count($content, 'Failed login attempt');
            $failedCodeCount = substr_count($content, 'Failed code attempt');
            
            $this->warn("Suspicious inputs: {$suspiciousCount}");
            $this->warn("Failed auth attempts: {$failedAuthCount}");
            $this->warn("Failed code attempts: {$failedCodeCount}");
        }
    }

    private function checkRateLimiting()
    {
        $this->info("\nRate Limiting Status:");
        
        $keys = Cache::get('security_*');
        if (!$keys) {
            $this->info('No rate limiting data found.');
            return;
        }

        foreach ($keys as $key => $attempts) {
            $this->warn("IP: {$key}, Requests: {$attempts}");
        }
    }

    private function cleanOldLogs()
    {
        $this->info('Cleaning old security logs...');
        
        // Clean old brute force attempts (older than 1 hour)
        $keys = Cache::get('brute_force_*');
        if ($keys) {
            foreach ($keys as $key => $attempts) {
                $lastAttempt = Cache::get("{$key}_timestamp");
                if ($lastAttempt && now()->diffInMinutes($lastAttempt) > 60) {
                    Cache::forget($key);
                    Cache::forget("{$key}_timestamp");
                }
            }
        }

        // Clean old rate limiting data (older than 1 hour)
        $keys = Cache::get('security_*');
        if ($keys) {
            foreach ($keys as $key => $attempts) {
                $lastAttempt = Cache::get("{$key}_timestamp");
                if ($lastAttempt && now()->diffInMinutes($lastAttempt) > 60) {
                    Cache::forget($key);
                    Cache::forget("{$key}_timestamp");
                }
            }
        }

        $this->info('Old security logs cleaned successfully.');
    }
} 