<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Konfigurationsspeicher mit Punktnotation.
 */
final class Config
{
    private static array $items = [];

    public static function load(array $config): void
    {
        self::$items = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }

    /** Setzt einen Wert; unterstützt Punktnotation wie get() (z. B. "aios.events_url"). */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $ref = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref[$last] = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }
}
