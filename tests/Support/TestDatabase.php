<?php

declare(strict_types=1);

namespace Kestello\Tests\Support;

use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\MigrationRunner;

/**
 * The database a test that boots the site (AuthTestCase) runs on.
 *
 * By default a new in-memory SQLite database per test, migrated. With
 * KESTELLO_TEST_DATABASE set to one connection config as JSON, the whole
 * suite runs on that MySQL, MariaDB or PostgreSQL database instead:
 *
 *   KESTELLO_TEST_DATABASE='{"driver":"pgsql","host":"127.0.0.1","port":5432,"database":"kestello_test","username":"kestello","password":"secret"}' php bin/cms test
 *
 * Migrating a server database for each test would take minutes, so it is
 * migrated once, and before each test only what the last test changed is
 * put back: the connection's listener (Connection::listen) sees each
 * statement, so the tables a test wrote to are emptied and get their rows
 * from after migrating back; tables it created are dropped; and when it
 * changed the schema of core's tables, or something else did (the
 * database was wiped), everything is dropped and migrated again.
 *
 * The database is wiped. Never point it at one that holds anything else,
 * nor at one in KESTELLO_TEST_DATABASES: tests drop every table there.
 */
final class TestDatabase
{
    private const string MIGRATIONS = __DIR__ . '/../../core/database/migrations';

    private static ?Connection $connection = null;

    /** @var list<string> core's tables after migrating */
    private static array $tables = [];

    /** @var array<string, list<array<string, mixed>>> table => its rows after migrating */
    private static array $seed = [];

    /** @var array<string, true> tables the running test wrote to */
    private static array $written = [];

    /** @var array<string, true> tables the running test created */
    private static array $created = [];

    private static bool $rebuild = false;
    private static bool $quiet = false;

    /** The server database's config, or null for SQLite. @return array<string, mixed>|null */
    public static function config(): ?array
    {
        $json = (string) getenv('KESTELLO_TEST_DATABASE');
        if (trim($json) === '') {
            return null;
        }
        $config = json_decode($json, true);
        if (!is_array($config) || !in_array($config['driver'] ?? null, ['mysql', 'pgsql'], true)) {
            throw new \RuntimeException('KESTELLO_TEST_DATABASE must be one connection config as JSON, with "driver" mysql or pgsql.');
        }
        $others = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($others) ? $others : [] as $other) {
            if (is_array($other) && self::same($config, $other)) {
                throw new \RuntimeException('KESTELLO_TEST_DATABASE is also in KESTELLO_TEST_DATABASES, whose tests drop every table: use another database.');
            }
        }

        return $config;
    }

    /** A connection for the next test, in the state migrating leaves it. */
    public static function connection(): Connection
    {
        $config = self::config();
        if ($config === null) {
            $connection = Connection::sqliteMemory();
            \Kestello\Framework\Database\DatabaseManager::setConnection($connection);
            self::migrate();

            return $connection;
        }
        if (self::$connection === null) {
            self::$connection = new Connection($config);
            self::$connection->listen(self::heard(...));
            self::$rebuild = true;
        }
        self::$quiet = true;
        try {
            self::$connection->forgetColumns();
            self::endTransactions(self::$connection);
            if (self::$rebuild || self::tablesChanged(self::$connection)) {
                self::rebuild(self::$connection);
            } else {
                self::reset(self::$connection);
            }
        } finally {
            self::$quiet = false;
            self::$written = [];
            self::$created = [];
            self::$rebuild = false;
        }

        return self::$connection;
    }

    /**
     * What a statement did to the database: which table it wrote to or
     * created, or whether it changed core's tables. @internal (the listener)
     */
    public static function heard(string $sql): void
    {
        if (self::$quiet) {
            return;
        }
        $sql = ltrim($sql);
        if (preg_match('/\A(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+[`"]?([A-Za-z0-9_]+)/i', $sql, $m) === 1) {
            self::$written[strtolower($m[1])] = true;

            return;
        }
        if (preg_match('/\A(?:CREATE|ALTER|DROP|RENAME|COMMENT)\b/i', $sql) !== 1) {
            return; // SELECT, SET, SAVEPOINT, …
        }
        // a table of the test's own (a plugin's migration) is dropped afterwards; anything else is rebuilt
        if (preg_match('/\ACREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)/i', $sql, $m) === 1 && !in_array(strtolower($m[1]), self::$tables, true)) {
            self::$created[strtolower($m[1])] = true;

            return;
        }
        if (preg_match('/\A(?:CREATE\s+(?:UNIQUE\s+)?INDEX\s+\S+\s+ON|ALTER\s+TABLE|DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?)\s*[`"]?([A-Za-z0-9_]+)/i', $sql, $m) === 1 && isset(self::$created[strtolower($m[1])])) {
            return;
        }
        self::$rebuild = true;
    }

    private static function migrate(): void
    {
        (new MigrationRunner(self::MIGRATIONS))->migrate();
    }

    private static function rebuild(Connection $c): void
    {
        $schema = $c->schema();
        $schema->withoutForeignKeyConstraints(static function () use ($schema): void {
            foreach ($schema->tables() as $table) {
                $schema->dropIfExists($table);
            }
        });
        $c->forgetColumns();
        $previous = \Kestello\Framework\Database\DatabaseManager::hasConnection() ? \Kestello\Framework\Database\DatabaseManager::getConnection() : null;
        \Kestello\Framework\Database\DatabaseManager::setConnection($c);
        try {
            self::migrate();
        } finally {
            $previous !== null ? \Kestello\Framework\Database\DatabaseManager::setConnection($previous) : null;
        }
        self::$tables = array_map('strtolower', $schema->tables());
        sort(self::$tables);
        self::$seed = [];
        foreach (self::$tables as $table) {
            $rows = $c->table($table)->get();
            if ($rows !== []) {
                self::$seed[$table] = array_map(static fn($row): array => (array) $row, $rows);
            }
        }
    }

    /** Put back what the last test changed. */
    private static function reset(Connection $c): void
    {
        $written = array_values(array_intersect(array_keys(self::$written), self::$tables));
        $created = array_keys(self::$created);
        if ($written === [] && $created === []) {
            return;
        }
        if ($c->driver() === 'pgsql') {
            // CASCADE: foreign keys stay as they are; it empties tables that point at these too,
            // which only a test wrote to (core's tables start empty, apart from the seed)
            foreach ($created as $table) {
                $c->raw("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
            }
            if ($written !== []) {
                $c->raw('TRUNCATE ' . implode(', ', array_map(static fn(string $t): string => '"' . $t . '"', $written)) . ' RESTART IDENTITY CASCADE');
            }
        } else {
            $c->raw('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($created as $table) {
                    $c->raw("DROP TABLE IF EXISTS `{$table}`");
                }
                foreach ($written as $table) {
                    $c->raw("TRUNCATE TABLE `{$table}`");   // AUTO_INCREMENT starts again too
                }
            } finally {
                $c->raw('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        foreach ($written as $table) {
            if (isset(self::$seed[$table])) {
                $c->table($table)->insertMany(self::$seed[$table]);
                if ($c->driver() === 'pgsql' && in_array('id', $c->cachedColumns($table), true)) {
                    // the seed set its ids: the sequence continues after them
                    $c->raw("SELECT setval(pg_get_serial_sequence('\"{$table}\"', 'id'), GREATEST((SELECT MAX(id) FROM \"{$table}\"), 1))");
                }
            }
        }
    }

    /** A test that stopped mid-transaction leaves it open: close it. */
    private static function endTransactions(Connection $c): void
    {
        while ($c->transactionLevel() > 0) {
            $c->rollback();
        }
        if ($c->getPdo()->inTransaction()) {
            $c->getPdo()->rollBack();
        }
    }

    /** Whether something other than this connection (a test of its own) dropped or added tables. */
    private static function tablesChanged(Connection $c): bool
    {
        $tables = array_map('strtolower', $c->schema()->tables());
        sort($tables);

        return $tables !== self::$tables;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function same(array $a, array $b): bool
    {
        foreach (['driver', 'host', 'port', 'database'] as $key) {
            if ((string) ($a[$key] ?? '') !== (string) ($b[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
