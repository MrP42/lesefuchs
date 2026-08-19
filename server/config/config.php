<?php
declare(strict_types=1);

/**
 * Zentrale Konfiguration. Werte kommen aus der .env (Prod: MySQL auf IONOS)
 * bzw. aus dev-boot.php (lokal: isolierte SQLite).
 */
return [
    'app' => [
        'name'           => env('APP_NAME', 'Lesefuchs'),
        'env'            => env('APP_ENV', 'production'),
        'url'            => env('APP_URL', 'https://lesefuchs.wolffappliedai.de'),
        'debug'          => (bool) env('APP_DEBUG', false),
        'secure_cookies' => (bool) env('APP_SECURE_COOKIES', true),
    ],

    'database' => [
        'driver'   => env('DB_DRIVER', 'mysql'),
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', ''),
        'username' => env('DB_USERNAME', ''),
        'password' => env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
    ],

    'packages' => [
        // Chunk-Größe für den Studio-Upload: unterhalb üblicher IONOS-Limits
        // (post_max_size/upload_max_filesize ~64 MB) mit Sicherheitsabstand.
        'chunk_bytes'      => 8 * 1024 * 1024,
        'max_size_bytes'   => 500 * 1024 * 1024,
        'upload_ttl_hours' => 24,
    ],

    'pairing' => [
        'code_ttl_minutes' => 10,
    ],
];
