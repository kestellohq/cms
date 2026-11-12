<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

/**
 * What a database's tables look like, in a form that compares across
 * MySQL, PostgreSQL and SQLite and across a site's history: each table's
 * columns and the indexes Blueprint names (idx_…, uniq_…), sorted. Column
 * order and a primary key's name differ per database and per history (a
 * column added later comes last), so they are left out.
 *
 * core/database/schema.json holds core's, from its migrations on a new
 * database (schema:snapshot). system:db-check and Site Health compare a
 * site's database with it, so a site whose tables drifted from what the
 * migrations make (a migration that ran differently on an old release)
 * is found before an update counts on them.
 *
 * @api
 */
final class SchemaSnapshot
{
    /** Tables that are not core's: plugins' (p_<slug>_…) and the database's own. */
    private const array IGNORED_PREFIXES = ['p_', 'sqlite_'];

    /**
     * @return array<string, array{columns: list<string>, indexes: list<string>}> table => shape, sorted
     */
    public static function describe(Connection $connection): array
    {
        $schema = $connection->schema();
        // two statements for the whole database, not two per table
        $allColumns = $schema->allColumns();
        $allIndexes = $schema->allIndexes();
        $tables = [];
        foreach ($schema->tables() as $name) {
            $table = strtolower($name);
            foreach (self::IGNORED_PREFIXES as $prefix) {
                if (str_starts_with($table, $prefix)) {
                    continue 2;
                }
            }
            $columns = array_map('strtolower', $allColumns[$name] ?? []);
            $indexes = array_values(array_filter(
                array_map('strtolower', $allIndexes[$name] ?? []),
                static fn(string $i): bool => str_starts_with($i, 'idx_') || str_starts_with($i, 'uniq_'),
            ));
            sort($columns);
            sort($indexes);
            $tables[$table] = ['columns' => $columns, 'indexes' => $indexes];
        }
        ksort($tables);

        return $tables;
    }

    /**
     * Run migrations on a new in-memory SQLite database and describe it.
     *
     * @param string|list<string> $migrations folders
     * @return array<string, array{columns: list<string>, indexes: list<string>}>
     */
    public static function fresh(string|array $migrations): array
    {
        $previous = DatabaseManager::hasConnection() ? DatabaseManager::getConnection() : null;
        $connection = Connection::sqliteMemory();
        DatabaseManager::setConnection($connection);
        try {
            (new MigrationRunner($migrations))->migrate();

            return self::describe($connection);
        } finally {
            $previous !== null ? DatabaseManager::setConnection($previous) : DatabaseManager::reset();
        }
    }

    /**
     * How $actual differs from $expected. Missing tables, columns and
     * indexes are problems: code counts on them. Extra ones are only noted:
     * left behind by an older version, or added by hand.
     *
     * @param array<string, array{columns: list<string>, indexes: list<string>}> $expected
     * @param array<string, array{columns: list<string>, indexes: list<string>}> $actual
     * @return array{missing: list<string>, extra: list<string>} "table", "table.column", "table index name"
     */
    public static function compare(array $expected, array $actual): array
    {
        $missing = [];
        $extra = [];
        foreach ($expected as $table => $shape) {
            if (!isset($actual[$table])) {
                $missing[] = "table {$table}";
                continue;
            }
            foreach (array_diff($shape['columns'], $actual[$table]['columns']) as $column) {
                $missing[] = "column {$table}.{$column}";
            }
            foreach (array_diff($shape['indexes'], $actual[$table]['indexes']) as $index) {
                $missing[] = "index {$index} on {$table}";
            }
            foreach (array_diff($actual[$table]['columns'], $shape['columns']) as $column) {
                $extra[] = "column {$table}.{$column}";
            }
            foreach (array_diff($actual[$table]['indexes'], $shape['indexes']) as $index) {
                $extra[] = "index {$index} on {$table}";
            }
        }
        foreach (array_diff_key($actual, $expected) as $table => $_) {
            $extra[] = "table {$table}";
        }

        return ['missing' => $missing, 'extra' => $extra];
    }

    /**
     * Leave out of a comparison what only one database has: $only maps a
     * driver to the lines (as compare() writes them) that exist on it alone,
     * such as SQLite's full-text table. They are not missing on the others,
     * and not extra where they belong.
     *
     * @param array{missing: list<string>, extra: list<string>} $diff
     * @param array<string, list<string>> $only
     * @return array{missing: list<string>, extra: list<string>}
     */
    public static function forDriver(array $diff, array $only, string $driver): array
    {
        $elsewhere = [];
        $listed = [];
        foreach ($only as $onDriver => $lines) {
            array_push($listed, ...$lines);
            if ($onDriver !== $driver) {
                array_push($elsewhere, ...$lines);
            }
        }

        // not missing where another database has it; never extra: where it belongs, it is expected
        return ['missing' => array_values(array_diff($diff['missing'], $elsewhere)), 'extra' => array_values(array_diff($diff['extra'], $listed))];
    }

    /**
     * @param array<string, array{columns: list<string>, indexes: list<string>}> $tables
     * @param array<string, list<string>> $only see forDriver()
     */
    public static function toJson(array $tables, array $only = []): string
    {
        ksort($only);

        return json_encode(['tables' => $tables, 'only' => $only], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array{tables: array<string, array{columns: list<string>, indexes: list<string>}>, only: array<string, list<string>>} */
    public static function load(string $file): array
    {
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['tables'] ?? null)) {
            throw new \RuntimeException("{$file} is not a schema snapshot.");
        }

        return ['tables' => $data['tables'], 'only' => is_array($data['only'] ?? null) ? $data['only'] : []];
    }

    /**
     * How a database differs from a snapshot file, leaving out what only
     * other databases have.
     *
     * @return array{missing: list<string>, extra: list<string>, tables: int}
     */
    public static function check(Connection $connection, string $file): array
    {
        $snapshot = self::load($file);
        $diff = self::forDriver(self::compare($snapshot['tables'], self::describe($connection)), $snapshot['only'], $connection->driver());

        return $diff + ['tables' => count($snapshot['tables'])];
    }
}
