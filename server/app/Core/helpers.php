<?php
declare(strict_types=1);

/**
 * Globale Hilfsfunktionen. Bewusst klein gehalten.
 */

if (!function_exists('e')) {
    /** HTML-Escaping für Ausgaben (XSS-Schutz). */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $root = dirname(__DIR__, 2); // wai-portal/
        return $path === '' ? $root : $root . '/' . ltrim($path, '/');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('icon')) {
    /**
     * Rendert ein Untitled-UI-Icon als Inline-SVG (vendored, MIT). Keine Icon-Font,
     * kein CDN. $name = semantischer Schlüssel aus config/icons.php; unbekannte Namen
     * fallen lautlos auf ein leeres SVG zurück (Layout bleibt stabil).
     */
    function icon(string $name, int $size = 24, string $class = ''): string
    {
        /** @var array<string,string>|null $set */
        static $set = null;
        if ($set === null) {
            $file = base_path('config/icons.php');
            $set = is_file($file) ? (array) require $file : [];
        }
        $inner = $set[$name] ?? '';
        $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"'
            . $cls . '>' . $inner . '</svg>';
    }
}

if (!function_exists('icon_has')) {
    /** Prüft, ob ein Icon-Name in config/icons.php existiert (für Glyph-Fallback). */
    function icon_has(string $name): bool
    {
        static $set = null;
        if ($set === null) {
            $file = base_path('config/icons.php');
            $set = is_file($file) ? (array) require $file : [];
        }
        return $name !== '' && isset($set[$name]);
    }
}

if (!function_exists('config')) {
    /** Zugriff auf Konfiguration via Punktnotation: config('app.name'). */
    function config(string $key, mixed $default = null): mixed
    {
        return \App\Core\Config::get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return \App\Core\Env::get($key, $default);
    }
}

if (!function_exists('old')) {
    /** Vorherigen Formularwert (Flash) holen. */
    function old(string $key, string $default = ''): string
    {
        $old = $_SESSION['_old'][$key] ?? $default;
        return is_string($old) ? $old : $default;
    }
}

if (!function_exists('str_random')) {
    /** Kryptografisch sicherer, URL-tauglicher Zufallsstring. */
    function str_random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text): string
    {
        // Umlaute zuerst ersetzen (auch Großbuchstaben), damit sie nicht beim
        // Lowercasing/Filtern verloren gehen (strtolower ist nicht multibyte-fähig).
        $map = [
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ];
        $text = strtr(trim($text), $map);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'n-a';
    }
}
