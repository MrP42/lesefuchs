<?php
declare(strict_types=1);

/**
 * Minimaler PSR-4-Autoloader (kein Composer nötig — IONOS-tauglich).
 * App\Foo\Bar  ->  app/Foo/Bar.php
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Core/helpers.php';
