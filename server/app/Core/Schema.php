<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Spaltendefinition mit fluent Modifiern.
 */
final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unique = false;
    public bool $isPrimary = false;

    public function __construct(public string $name, public string $type) {}

    public function nullable(bool $value = true): self { $this->nullable = $value; return $this; }
    public function default(mixed $value): self { $this->default = $value; $this->hasDefault = true; return $this; }
    public function unique(bool $value = true): self { $this->unique = $value; return $this; }
}

/**
 * Tabellen-Bauplan; kompiliert dialekt-bewusst (SQLite / MySQL).
 */
final class Blueprint
{
    /** @var Column[] */
    public array $columns = [];
    /** @var array<int,array{col:string,ref:string,refcol:string,onDelete:string}> */
    public array $foreignKeys = [];
    /** @var array<int,string[]> */
    public array $indexes = [];
    /** @var array<int,string[]> */
    public array $uniqueIndexes = [];
    public ?string $primaryKey = null;

    public function __construct(public string $table, public string $driver) {}

    public function id(string $name = 'id'): Column
    {
        $type = $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        $col = new Column($name, $type);
        $col->isPrimary = true;
        $this->primaryKey = $name;
        $this->columns[] = $col;
        return $col;
    }

    public function integer(string $name): Column { return $this->add($name, $this->driver === 'sqlite' ? 'INTEGER' : 'INT'); }
    public function bigint(string $name): Column { return $this->add($name, $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT'); }
    public function string(string $name, int $length = 255): Column { return $this->add($name, "VARCHAR($length)"); }
    public function text(string $name): Column { return $this->add($name, 'TEXT'); }
    /** Große Text-/JSON-Spalten: MySQL-TEXT (64 KB) reicht dafür nicht — MEDIUMTEXT (16 MB). */
    public function mediumText(string $name): Column { return $this->add($name, $this->driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT'); }
    public function boolean(string $name): Column { return $this->add($name, $this->driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)'); }
    public function datetime(string $name): Column { return $this->add($name, 'DATETIME'); }
    public function decimal(string $name, int $precision = 10, int $scale = 2): Column { return $this->add($name, "DECIMAL($precision,$scale)"); }

    public function foreignId(string $name): Column
    {
        return $this->add($name, $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED');
    }

    public function references(string $column, string $table, string $refColumn = 'id', string $onDelete = 'CASCADE'): void
    {
        $this->foreignKeys[] = ['col' => $column, 'ref' => $table, 'refcol' => $refColumn, 'onDelete' => $onDelete];
    }

    public function index(string ...$columns): void
    {
        $this->indexes[] = $columns;
    }

    /**
     * Zusammengesetzter (oder einspaltiger) UNIQUE-Index. Anders als
     * Column::unique() auch über mehrere Spalten; NULL-Werte dürfen dabei
     * mehrfach vorkommen (MySQL wie SQLite) — gewollt für Provenance-Spalten.
     */
    public function uniqueIndex(string ...$columns): void
    {
        $this->uniqueIndexes[] = $columns;
    }

    public function timestamps(): void
    {
        $this->add('created_at', 'DATETIME')->nullable();
        $this->add('updated_at', 'DATETIME')->nullable();
    }

    private function add(string $name, string $type): Column
    {
        $col = new Column($name, $type);
        $this->columns[] = $col;
        return $col;
    }

    public function compile(): string
    {
        $parts = [];
        foreach ($this->columns as $col) {
            $parts[] = $this->compileColumn($col);
        }
        if ($this->primaryKey === null) {
            // kein impliziter PK
        }
        foreach ($this->foreignKeys as $fk) {
            $parts[] = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE %s',
                $fk['col'], $fk['ref'], $fk['refcol'], $fk['onDelete']
            );
        }
        $body = implode(",\n  ", $parts);
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        return "CREATE TABLE IF NOT EXISTS {$this->table} (\n  {$body}\n){$suffix}";
    }

    /** @return string[] zusätzliche CREATE INDEX-Statements */
    public function compileIndexes(): array
    {
        $stmts = [];
        // MySQL kennt kein "CREATE INDEX IF NOT EXISTS" — nur SQLite. Migrationen
        // laufen ohnehin nur einmal (per migrations-Tabelle nachgehalten).
        $ifNotExists = $this->driver === 'sqlite' ? 'IF NOT EXISTS ' : '';
        foreach ($this->indexes as $cols) {
            $name = 'idx_' . $this->table . '_' . implode('_', $cols);
            $stmts[] = sprintf('CREATE INDEX %s%s ON %s (%s)', $ifNotExists, $name, $this->table, implode(', ', $cols));
        }
        foreach ($this->uniqueIndexes as $cols) {
            $name = 'uniq_' . $this->table . '_' . implode('_', $cols);
            $stmts[] = sprintf('CREATE UNIQUE INDEX %s%s ON %s (%s)', $ifNotExists, $name, $this->table, implode(', ', $cols));
        }
        return $stmts;
    }

    private function compileColumn(Column $col): string
    {
        $sql = $col->name . ' ' . $col->type;

        if ($col->isPrimary) {
            if ($this->driver === 'sqlite') {
                $sql .= ' PRIMARY KEY AUTOINCREMENT';
            } else {
                $sql .= ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
            }
            return $sql;
        }

        $sql .= $col->nullable ? ' NULL' : ' NOT NULL';

        if ($col->hasDefault) {
            $sql .= ' DEFAULT ' . $this->quoteDefault($col->default);
        }
        if ($col->unique) {
            $sql .= ' UNIQUE';
        }
        return $sql;
    }

    private function quoteDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}

/**
 * Schema-Fassade: erzeugt/dropt Tabellen über einen Blueprint.
 */
final class Schema
{
    public function __construct(private PDO $pdo, private string $driver) {}

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, $this->driver);
        $callback($blueprint);
        $this->pdo->exec($blueprint->compile());
        foreach ($blueprint->compileIndexes() as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    public function dropIfExists(string $table): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    public function hasTable(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        } else {
            $stmt = $this->pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        }
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
