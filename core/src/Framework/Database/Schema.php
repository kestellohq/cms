<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use Closure;

/**
 * Schema builder for creating and modifying database tables.
 */
final class Schema
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** Active table prefix restriction (set while a plugin's migrations run). */
    private static ?string $requiredPrefix = null;

    /**
     * Restrict schema changes to tables starting with $prefix while $callback runs.
     * Used so plugin migrations can only touch their own p_<slug>_* tables.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function restrictTo(?string $prefix, callable $callback): mixed
    {
        $previous = self::$requiredPrefix;
        self::$requiredPrefix = $prefix;

        try {
            return $callback();
        } finally {
            self::$requiredPrefix = $previous;
        }
    }

    /** @var list<string> every installed package's table prefix */
    private static array $packagePrefixes = [];

    /**
     * Tell the guard about every installed package's prefix, so "p_acme_" can't
     * reach "p_acme_seo_orders", which belongs to the plugin "acme-seo".
     *
     * @param list<string> $prefixes
     */
    public static function packagePrefixes(array $prefixes): void
    {
        self::$packagePrefixes = $prefixes;
    }

    private static function guard(string ...$tables): void
    {
        if (self::$requiredPrefix === null) {
            return;
        }

        foreach ($tables as $table) {
            foreach (self::$packagePrefixes as $other) {
                if (strlen($other) > strlen(self::$requiredPrefix) && str_starts_with($other, self::$requiredPrefix) && str_starts_with($table, $other)) {
                    throw new \RuntimeException(sprintf('Table "%s" belongs to another package (prefix "%s").', $table, $other));
                }
            }
            if (!str_starts_with($table, self::$requiredPrefix)) {
                throw new \RuntimeException(sprintf(
                    'Table "%s" is outside this migration\'s namespace; table names must start with "%s".',
                    $table,
                    self::$requiredPrefix,
                ));
            }
        }
    }

    /** Create a table. */
    public function create(string $table, Closure $callback): void
    {
        self::guard($table);
        $blueprint = new Blueprint($table, creating: true);
        $callback($blueprint);

        $this->connection->forgetColumns($table);
        $this->run($this->connection->schemaGrammar()->compileCreate($blueprint, $this->connection->config()));
    }

    /** Change an existing table: add/drop/rename columns, indexes, foreign keys. */
    public function table(string $table, Closure $callback): void
    {
        self::guard($table);
        $blueprint = new Blueprint($table, creating: false);
        $callback($blueprint);

        $this->connection->forgetColumns($table);
        $this->run($this->connection->schemaGrammar()->compileAlter($blueprint));
    }

    public function drop(string $table): void
    {
        self::guard($table);
        $this->connection->forgetColumns($table);
        $this->connection->raw($this->connection->schemaGrammar()->compileDrop($table));
    }

    public function dropIfExists(string $table): void
    {
        self::guard($table);
        $this->connection->forgetColumns($table);
        $this->connection->raw($this->connection->schemaGrammar()->compileDrop($table, ifExists: true));
    }

    public function rename(string $from, string $to): void
    {
        self::guard($from, $to);
        $this->connection->forgetColumns();
        $this->connection->raw($this->connection->schemaGrammar()->compileRename($from, $to));
    }

    public function hasTable(string $table): bool
    {
        [$sql, $bindings] = $this->connection->schemaGrammar()->compileHasTable($table);
        $row = $this->connection->query($sql, $bindings)->fetch();

        return (int) ($row['n'] ?? 0) > 0;
    }

    /** @return list<string> the tables in this connection's database (or schema), by name */
    public function tables(): array
    {
        [$sql, $bindings] = $this->connection->schemaGrammar()->compileTables();

        return array_map(static fn(array $r): string => (string) ($r['name'] ?? $r['NAME'] ?? ''), $this->connection->select($sql, $bindings));
    }

    /** @return list<string> column names, in table order */
    public function columns(string $table): array
    {
        [$sql, $bindings] = $this->connection->schemaGrammar()->compileColumns($table);

        return array_map(static fn(array $r): string => (string) ($r['name'] ?? $r['NAME'] ?? ''), $this->connection->select($sql, $bindings));
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map('strtolower', $this->columns($table)), true);
    }

    /**
     * The names of a table's indexes, as the database reports them: the ones
     * Blueprint made are idx_<table>_<columns> and uniq_<table>_<columns>;
     * a primary key's name differs per database.
     *
     * @return list<string>
     */
    public function indexes(string $table): array
    {
        [$sql, $bindings] = $this->connection->schemaGrammar()->compileIndexes($table);

        return array_map(static fn(array $r): string => (string) ($r['name'] ?? $r['NAME'] ?? ''), $this->connection->select($sql, $bindings));
    }

    /** Whether a table has an index of this name (see indexes()). */
    public function hasIndex(string $table, string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $this->indexes($table)), true);
    }

    /**
     * Run $callback with foreign key checks off (dropping many tables, or
     * loading rows in any order, as a restore does).
     *
     * PostgreSQL has no switch for this short of superuser rights, so its
     * foreign keys are dropped and put back afterwards from their own
     * definitions. Putting them back checks every row: data that breaks a key
     * fails here instead of being kept.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withoutForeignKeyConstraints(Closure $callback): mixed
    {
        if ($this->connection->driver() === 'pgsql') {
            return $this->withoutPostgresForeignKeys($callback);
        }
        $grammar = $this->connection->schemaGrammar();
        if (($sql = $grammar->compileDisableForeignKeys()) !== null) {
            $this->connection->raw($sql);
        }

        try {
            return $callback();
        } finally {
            if (($sql = $grammar->compileEnableForeignKeys()) !== null) {
                $this->connection->raw($sql);
            }
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withoutPostgresForeignKeys(Closure $callback): mixed
    {
        $keys = $this->connection->select(
            "SELECT conrelid::regclass::text AS tbl, conname AS name, pg_get_constraintdef(oid) AS def
               FROM pg_constraint WHERE contype = 'f' AND connamespace = current_schema()::regnamespace ORDER BY conname",
        );
        foreach ($keys as $k) {
            $this->connection->raw("ALTER TABLE {$k['tbl']} DROP CONSTRAINT " . $this->wrapName((string) $k['name']));
        }
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            try {
                $this->restorePostgresForeignKeys($keys);
            } catch (\Throwable) {
                // the first error is the one worth reporting
            }
            throw $e;
        }
        $this->restorePostgresForeignKeys($keys);

        return $result;
    }

    /**
     * Put back the foreign keys of tables that still exist, unless the
     * callback made them again (a table dropped and created anew).
     *
     * @param list<array<string, mixed>> $keys
     */
    private function restorePostgresForeignKeys(array $keys): void
    {
        $present = array_flip(array_map(static fn(array $r) => (string) $r['name'], $this->connection->select(
            "SELECT conname AS name FROM pg_constraint WHERE contype = 'f' AND connamespace = current_schema()::regnamespace",
        )));
        foreach ($keys as $k) {
            $exists = $this->connection->select('SELECT to_regclass(?) AS t', [(string) $k['tbl']])[0]['t'] ?? null;
            if ($exists === null || isset($present[(string) $k['name']])) {
                continue;
            }
            $this->connection->raw("ALTER TABLE {$k['tbl']} ADD CONSTRAINT " . $this->wrapName((string) $k['name']) . ' ' . $k['def']);
        }
    }

    private function wrapName(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->connection->raw($sql);
        }
    }
}
