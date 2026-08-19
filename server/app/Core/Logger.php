<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Datei-Logger (storage/logs/app.log). Bewusst minimal.
 */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            now(),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
}
