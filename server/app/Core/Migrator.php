<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Führt Migrationsdateien aus database/migrations/ aus und protokolliert sie.
 * Jede Datei gibt ein Objekt mit up(Schema, PDO) und down(Schema, PDO) zurück.
 */
final class Migrator
{
    private Schema $schema;

    public function __construct(private PDO $pdo, private string $migrationsPath)
    {
        $this->schema = new Schema($pdo, Database::driver());
        $this->ensureMigrationsTable();
    }

    private function ensureMigrationsTable(): void
    {
        $this->schema->create('migrations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('batch');
            $t->datetime('applied_at')->nullable();
        });
    }

    /** @return string[] Namen der angewandten Migrationen */
    public function migrate(): array
    {
        $applied = $this->appliedNames();
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.php') ?: [];
        sort($files);
        $batch = $this->nextBatch();
        $ran = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $applied, true)) {
                continue;
            }
            $migration = require $file;
            $migration->up($this->schema, $this->pdo);
            $stmt = $this->pdo->prepare('INSERT INTO migrations (name, batch, applied_at) VALUES (?, ?, ?)');
            $stmt->execute([$name, $batch, now()]);
            $ran[] = $name;
        }
        return $ran;
    }

    public function rollback(): array
    {
        $batch = (int) $this->pdo->query('SELECT MAX(batch) FROM migrations')->fetchColumn();
        if ($batch === 0) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT name FROM migrations WHERE batch = ? ORDER BY id DESC');
        $stmt->execute([$batch]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $rolled = [];
        foreach ($names as $name) {
            $file = rtrim($this->migrationsPath, '/') . '/' . $name . '.php';
            if (is_file($file)) {
                $migration = require $file;
                $migration->down($this->schema, $this->pdo);
            }
            $del = $this->pdo->prepare('DELETE FROM migrations WHERE name = ?');
            $del->execute([$name]);
            $rolled[] = $name;
        }
        return $rolled;
    }

    /** @return string[] */
    private function appliedNames(): array
    {
        return $this->pdo->query('SELECT name FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function nextBatch(): int
    {
        return ((int) $this->pdo->query('SELECT MAX(batch) FROM migrations')->fetchColumn()) + 1;
    }
}
