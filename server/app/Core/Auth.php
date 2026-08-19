<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentifizierung: Login mit Throttling, Session-Handling, Rollenprüfung.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900; // 15 Minuten

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));

        if (self::tooManyAttempts($email)) {
            Logger::warning('Login blockiert (Throttle)', ['email' => $email]);
            return false;
        }

        $user = Db::first('SELECT * FROM users WHERE email = ?', [$email]);

        if ($user === null
            || ($user['status'] ?? '') === 'disabled'
            || !Hash::verify($password, $user['password_hash'] ?? null)) {
            self::recordAttempt($email);
            return false;
        }

        self::clearAttempts($email);

        if (!empty($user['password_hash']) && Hash::needsRehash($user['password_hash'])) {
            Db::update('users', ['password_hash' => Hash::make($password)], 'id = :id', ['id' => $user['id']]);
        }

        self::login($user);
        Db::update('users', ['last_login_at' => now()], 'id = :id', ['id' => $user['id']]);
        return true;
    }

    public static function login(array $user): void
    {
        Session::start();
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        Session::forget('user_id');
        Session::regenerate();
        self::$user = null;
        self::$loaded = true;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u !== null ? (int) $u['id'] : null;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = Session::get('user_id');
        self::$user = $id !== null ? Db::first('SELECT * FROM users WHERE id = ?', [(int) $id]) : null;
        return self::$user;
    }

    public static function roleKey(?array $user = null): ?string
    {
        $user ??= self::user();
        return $user !== null ? (string) ($user['role'] ?? '') : null;
    }

    /** Familie des angemeldeten Nutzers (Mandanten-Scope). */
    public static function familyId(): ?int
    {
        $u = self::user();
        $fid = $u['family_id'] ?? null;
        return $fid !== null ? (int) $fid : null;
    }

    public static function hasRole(string $key): bool
    {
        return self::roleKey() === $key;
    }

    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    // ---- Throttling ----
    public static function recentAttempts(string $email): int
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at >= ?',
            [strtolower(trim($email)), $since]
        );
    }

    public static function tooManyAttempts(string $email): bool
    {
        return self::recentAttempts($email) >= self::MAX_ATTEMPTS;
    }

    private static function recordAttempt(string $email): void
    {
        Db::insert('login_attempts', ['identifier' => $email, 'attempted_at' => now()]);
    }

    private static function clearAttempts(string $email): void
    {
        Db::delete('login_attempts', 'identifier = :id', ['id' => $email]);
    }

    /** Nur für Tests: internen Zustand zurücksetzen. */
    public static function reset(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    /** Setzt den Prinzipal für einen zustandslosen (API-)Request — keine Session. */
    public static function setUserForRequest(array $user): void
    {
        self::$user = $user;
        self::$loaded = true;
    }
}
