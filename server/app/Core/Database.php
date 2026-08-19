<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO-Verbindungsfabrik mit Treiber-Umschaltung.
 *   - MySQL/MariaDB  (Produktion / IONOS)
 *   - SQLite         (lokal / Tests, inkl. :memory:)
 *
 * Eine einzige, anwendungsweite Verbindung (Singleton je Konfiguration).
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = '';

    public static function connect(?array $config = null): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $config ??= Config::get('database', []);
        $driver = $config['driver'] ?? 'sqlite';
        self::$driver = $driver;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = $config['database'] ?? ':memory:';
                if ($path !== ':memory:' && !is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0775, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } elseif ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $config['host'] ?? '127.0.0.1',
                    (string) ($config['port'] ?? 3306),
                    $config['database'] ?? '',
                    $config['charset'] ?? 'utf8mb4'
                );
                // FOUND_ROWS: rowCount() zählt GETROFFENE Zeilen (wie SQLite),
                // nicht nur veränderte — sonst liefern CAS-Checks (`… === 1`)
                // bei No-op-UPDATEs (z. B. Heartbeat in derselben Sekunde)
                // fälschlich 0 und melden 409 (Review, MySQL-Semantik).
                $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
                $pdo = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', $options);
            } else {
                throw new RuntimeException("Nicht unterstützter DB-Treiber: {$driver}");
            }
        } catch (PDOException $e) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(), (int) $e->getCode());
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::connect();
    }

    /** Für Tests: Verbindung zurücksetzen. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$driver = '';
    }
}
