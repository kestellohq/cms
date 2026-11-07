<?php

declare(strict_types=1);

namespace Kestello\Cms\Backup;

use Kestello\Framework\Database\Connection;
use PDO;
use RuntimeException;

/**
 * Rows in and out of any supported database as JSON lines, so a backup made
 * on MySQL restores on Postgres or SQLite. The schema is never dumped: it
 * comes from migrations.
 *
 * Values: integers, floats, strings and null as they are; booleans as 0/1;
 * bytes that aren't UTF-8 (binary columns) as {"$b64": "…"}.
 */
final class DatabaseDump
{
    private const int PAGE = 500;

    public function __construct(private readonly Connection $db) {}

    /** @return list<string> */
    public function tables(): array
    {
        return $this->db->schema()->tables();
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return $this->db->schema()->columns($table);
    }

    /**
     * Write every row of $table to $out, one JSON object per line.
     *
     * @param resource $out
     * @return int rows written
     */
    /**
     * Run $work (the exports of every table) inside one read snapshot, so a
     * backup taken while people edit has orders and their lines from the same
     * moment. Without one (already inside a transaction) it runs as it is.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function snapshot(callable $work): mixed
    {
        $pdo = $this->db->getPdo();
        if ($this->db->transactionLevel() > 0 || $pdo->inTransaction()) {
            return $work();
        }
        match ($this->db->driver()) {
            'mysql' => (static function () use ($pdo): void {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            })(),
            'pgsql' => (static function () use ($pdo): void {
                $pdo->beginTransaction();
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            })(),
            default => $pdo->beginTransaction(),
        };
        try {
            return $work();
        } finally {
            try {
                $pdo->inTransaction() ? $pdo->commit() : $pdo->exec('COMMIT');   // read only: nothing to keep or undo
            } catch (\Throwable) {
            }
        }
    }

    public function export(string $table, mixed $out): int
    {
        $columns = $this->columns($table);
        $wrapped = $this->db->grammar()->wrapTable($table);
        $count = 0;

        if (in_array('id', $columns, true)) {
            // keyset pages: memory stays flat on big tables
            $id = $this->db->grammar()->wrap('id');
            $last = null;
            do {
                $rows = $last === null
                    ? $this->db->select("SELECT * FROM {$wrapped} ORDER BY {$id} LIMIT " . self::PAGE)
                    : $this->db->select("SELECT * FROM {$wrapped} WHERE {$id} > ? ORDER BY {$id} LIMIT " . self::PAGE, [$last]);
                foreach ($rows as $row) {
                    fwrite($out, self::line($row));
                    $last = $row['id'];
                }
                $count += count($rows);
            } while (count($rows) === self::PAGE);

            return $count;
        }

        // tables without an id (pivots) are narrow: one statement
        $statement = $this->db->query("SELECT * FROM {$wrapped}");
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            fwrite($out, self::line($row));
            $count++;
        }

        return $count;
    }

    /**
     * Insert JSON lines into $table. Columns the table no longer has are
     * dropped and reported; columns it gained take their defaults.
     *
     * @param iterable<string> $lines
     * @return array{rows: int, dropped: list<string>}
     */
    public function import(string $table, iterable $lines): array
    {
        $have = array_flip($this->columns($table));
        $dropped = [];
        $batch = [];
        $rows = 0;
        $keys = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($row)) {
                throw new RuntimeException("{$table}: a row is not an object.");
            }
            foreach (array_keys($row) as $column) {
                if (!isset($have[$column])) {
                    $dropped[$column] = true;
                    unset($row[$column]);
                }
            }
            $rowKeys = array_keys($row);
            if ($keys !== null && $rowKeys !== $keys) {
                $rows += $this->insert($table, $keys, $batch);
                $batch = [];
            }
            $keys = $rowKeys;
            $batch[] = $row;
            // stay under every engine's bound-parameter limit (SQLite: 999 on old builds)
            if (count($batch) * max(1, count($keys)) >= 900) {
                $rows += $this->insert($table, $keys, $batch);
                $batch = [];
            }
        }
        if ($batch !== [] && $keys !== null) {
            $rows += $this->insert($table, $keys, $batch);
        }

        return ['rows' => $rows, 'dropped' => array_keys($dropped)];
    }

    /** Drop every table, foreign keys off. */
    public function dropAll(): void
    {
        $schema = $this->db->schema();
        $schema->withoutForeignKeyConstraints(function () use ($schema): void {
            foreach ($this->tables() as $table) {
                if ($this->db->driver() === 'pgsql') {
                    $this->db->raw('DROP TABLE IF EXISTS ' . $this->db->grammar()->wrapTable($table) . ' CASCADE');
                } else {
                    $schema->dropIfExists($table);
                }
            }
        });
        $this->db->forgetColumns();
    }

    /**
     * Postgres identity columns don't move when ids are inserted explicitly:
     * point each one past the highest id.
     *
     * @param list<string> $tables
     */
    public function resetSequences(array $tables): void
    {
        if ($this->db->driver() !== 'pgsql') {
            return;
        }
        foreach ($tables as $table) {
            if (!in_array('id', $this->columns($table), true)) {
                continue;
            }
            $wrapped = $this->db->grammar()->wrapTable($table);
            $sequence = $this->db->select('SELECT pg_get_serial_sequence(?, ?) AS s', [$table, 'id'])[0]['s'] ?? null;
            if (is_string($sequence) && $sequence !== '') {
                $this->db->select("SELECT setval(?, COALESCE((SELECT MAX(id) FROM {$wrapped}), 1), (SELECT MAX(id) FROM {$wrapped}) IS NOT NULL)", [$sequence]);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param list<array<string, mixed>> $rows
     */
    private function insert(string $table, array $keys, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $grammar = $this->db->grammar();
        $placeholders = '(' . implode(', ', array_fill(0, count($keys), '?')) . ')';
        $sql = 'INSERT INTO ' . $grammar->wrapTable($table) . ' (' . implode(', ', array_map($grammar->wrap(...), $keys)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $placeholders));
        $statement = $this->db->getPdo()->prepare($sql);
        $i = 1;
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $value = $row[$key];
                if ($value === null) {
                    $statement->bindValue($i++, null, PDO::PARAM_NULL);
                } elseif (is_array($value) && isset($value['$b64']) && is_string($value['$b64'])) {
                    $statement->bindValue($i++, (string) base64_decode($value['$b64'], true), PDO::PARAM_LOB);
                } elseif (is_array($value)) {
                    throw new RuntimeException("{$table}.{$key}: unexpected nested value.");
                } else {
                    // as text: every engine casts it to the column's type (incl. Postgres booleans from 0/1)
                    $statement->bindValue($i++, is_float($value) ? self::float($value) : (string) $value, PDO::PARAM_STR);
                }
            }
        }
        $statement->execute();

        return count($rows);
    }

    /** @param array<string, mixed> $row */
    private static function line(array $row): string
    {
        foreach ($row as $key => $value) {
            if (is_resource($value)) {
                $value = (string) stream_get_contents($value);      // Postgres bytea
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $value = ['$b64' => base64_encode($value)];
            }
            $row[$key] = $value;
        }

        return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function float(float $value): string
    {
        $text = var_export($value, true);

        return str_contains($text, 'E') ? sprintf('%.17g', $value) : $text;
    }
}
