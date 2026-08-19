<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sehr schlanker .env-Parser (kein Composer/vlucas nötig).
 * Liest KEY=VALUE-Zeilen, ignoriert Kommentare (#) und leere Zeilen.
 */
final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Anführungszeichen entfernen
            if (strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$vars)) {
            $v = self::$vars[$key];
            return match (strtolower((string) $v)) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => $v,
            };
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
