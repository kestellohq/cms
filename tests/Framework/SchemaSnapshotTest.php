<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Cms\Console\Database\SchemaSnapshotCommand;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Framework\Database\SchemaSnapshot;
use Kestello\Framework\Testing\TestCase;

/**
 * core/database/schema.json against core's migrations on every database:
 * in-memory SQLite, plus KESTELLO_TEST_DATABASES (see DatabaseTest; those
 * databases are wiped).
 */
final class SchemaSnapshotTest extends TestCase
{
    private const string MIGRATIONS = __DIR__ . '/../../core/database/migrations';
    private const string FILE = __DIR__ . '/../../core/database/schema.json';

    public function testTheCommittedSnapshotIsWhatTheMigrationsMake(): void
    {
        $this->assertSame(
            SchemaSnapshot::toJson(SchemaSnapshot::fresh(self::MIGRATIONS), SchemaSnapshotCommand::ONLY),
            (string) file_get_contents(self::FILE),
            'core/database/schema.json is out of date. Run: php bin/cms schema:snapshot',
        );
    }

    public function testEveryDatabaseMakesTheSameTables(): void
    {
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($extra) ? $extra : [] as $config) {
            $c = new Connection($config);
            $previous = DatabaseManager::hasConnection() ? DatabaseManager::getConnection() : null;
            DatabaseManager::setConnection($c);
            try {
                self::dropAll($c);
                (new MigrationRunner(self::MIGRATIONS))->migrate();
                $diff = SchemaSnapshot::check($c, self::FILE);
                // a difference that belongs to one database goes in SchemaSnapshotCommand::ONLY
                $this->assertSame(['missing' => [], 'extra' => []], ['missing' => $diff['missing'], 'extra' => $diff['extra']], $c->driver());
            } finally {
                self::dropAll($c);
                $previous !== null ? DatabaseManager::setConnection($previous) : DatabaseManager::reset();
            }
        }
    }

    public function testADriftedDatabaseIsFound(): void
    {
        $c = Connection::sqliteMemory();
        $previous = DatabaseManager::hasConnection() ? DatabaseManager::getConnection() : null;
        DatabaseManager::setConnection($c);
        try {
            (new MigrationRunner(self::MIGRATIONS))->migrate();
            $this->assertSame(['missing' => [], 'extra' => [], 'tables' => 55], SchemaSnapshot::check($c, self::FILE) + ['tables' => 0]);

            // what an old release left behind: a missing index, a missing column, an unused table
            $c->raw('DROP INDEX idx_audit_log_event');
            $c->raw('ALTER TABLE users DROP COLUMN locale');
            $c->raw('CREATE TABLE old_things (id INTEGER)');
            $c->raw('CREATE TABLE p_acme_items (id INTEGER)');   // a plugin's: not core's business
            $diff = SchemaSnapshot::check($c, self::FILE);
            $this->assertSame(['index idx_audit_log_event on audit_log', 'column users.locale'], $diff['missing']);
            $this->assertSame(['table old_things'], $diff['extra']);
        } finally {
            $previous !== null ? DatabaseManager::setConnection($previous) : DatabaseManager::reset();
        }
    }

    public function testWhatOnlyAnotherDatabaseHasIsLeftOut(): void
    {
        $diff = ['missing' => ['table search_documents_fts', 'column users.locale'], 'extra' => ['column search_documents.vector']];
        $only = ['sqlite' => ['table search_documents_fts'], 'pgsql' => ['column search_documents.vector']];
        $this->assertSame(['missing' => ['column users.locale'], 'extra' => []], SchemaSnapshot::forDriver($diff, $only, 'pgsql'), 'expected where it belongs');
        $this->assertSame(['missing' => ['column users.locale'], 'extra' => []], SchemaSnapshot::forDriver($diff, $only, 'mysql'));
        $this->assertSame(['missing' => ['table search_documents_fts', 'column users.locale'], 'extra' => []], SchemaSnapshot::forDriver($diff, $only, 'sqlite'));
    }

    public function testColumnOrderAndPrimaryKeyNamesDoNotCount(): void
    {
        $a = Connection::sqliteMemory();
        $a->raw('CREATE TABLE t (id INTEGER PRIMARY KEY, b TEXT, a TEXT)');
        $a->raw('CREATE INDEX idx_t_a ON t (a)');
        $b = Connection::sqliteMemory();
        $b->raw('CREATE TABLE t (a TEXT, id INTEGER, b TEXT, CONSTRAINT some_pk PRIMARY KEY (id))');
        $b->raw('CREATE INDEX idx_t_a ON t (a)');
        $this->assertSame(SchemaSnapshot::describe($a), SchemaSnapshot::describe($b));
        $this->assertSame(['t' => ['columns' => ['a', 'b', 'id'], 'indexes' => ['idx_t_a']]], SchemaSnapshot::describe($a));
    }

    private static function dropAll(Connection $c): void
    {
        $schema = $c->schema();
        $schema->withoutForeignKeyConstraints(function () use ($schema): void {
            foreach ($schema->tables() as $table) {
                $schema->dropIfExists($table);
            }
        });
        $c->forgetColumns();
    }
}
