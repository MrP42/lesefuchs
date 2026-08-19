<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Schlanker Query-Helfer auf Basis von PDO (Prepared Statements überall).
 */
final class Db
{
    public static function pdo(): PDO
    {
        return Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $placeholders)
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bindable($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(static fn($c) => "$c = :set_$c", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params["set_$k"] = self::normalize($v);
        }
        foreach ($whereParams as $k => $v) {
            $params[$k] = $v;
        }
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $stmt = self::pdo()->prepare("DELETE FROM {$table} WHERE {$where}");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Führt $fn in einer DB-Transaktion aus (Commit bei Erfolg, Rollback bei
     * jeder Exception). Verschachtelte Aufrufe treten der äußeren Transaktion
     * bei (kein Savepoint-Emulieren — bewusst schlicht, MySQL+SQLite).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function bindable(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = self::normalize($v);
        }
        return $out;
    }

    private static function normalize(mixed $v): mixed
    {
        return is_bool($v) ? ($v ? 1 : 0) : $v;
    }
}
