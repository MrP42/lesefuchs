<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSRF-Schutz über Session-Token (Double-Submit via Hidden-Field/Header).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!Session::has(self::KEY)) {
            Session::set(self::KEY, str_random(32));
        }
        return (string) Session::get(self::KEY);
    }

    public static function check(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $stored = (string) Session::get(self::KEY, '');
        return $stored !== '' && hash_equals($stored, $token);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}
