<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Passwort-Hashing (bcrypt). Re-Hash-Erkennung integriert.
 */
final class Hash
{
    public static function make(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public static function verify(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}
