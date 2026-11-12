<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;
use Kestello\Tests\Support\TestDatabase;

/** How the suite's server database knows what a test changed (see TestDatabase). */
final class TestDatabaseTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['tables', 'written', 'created', 'rebuild', 'quiet'] as $name) {
            $this->saved[$name] = (new \ReflectionProperty(TestDatabase::class, $name))->getValue();
        }
        $this->set('tables', ['entries', 'users']);
        $this->set('written', []);
        $this->set('created', []);
        $this->set('rebuild', false);
        $this->set('quiet', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->set($name, $value);
        }
        putenv('KESTELLO_TEST_DATABASE');
        parent::tearDown();
    }

    private function set(string $name, mixed $value): void
    {
        (new \ReflectionProperty(TestDatabase::class, $name))->setValue(null, $value);
    }

    private function get(string $name): mixed
    {
        return (new \ReflectionProperty(TestDatabase::class, $name))->getValue();
    }

    public function testWritesMarkTheirTable(): void
    {
        foreach ([
            'INSERT INTO "users" ("username") VALUES (?)',
            '  insert ignore into `entries` (`id`) values (?)',
            'UPDATE "users" SET "email" = ? WHERE "id" = ?',
            'DELETE FROM `Entries` WHERE id = ?',
            'TRUNCATE TABLE "audit_log"',
        ] as $sql) {
            TestDatabase::heard($sql);
        }
        $this->assertSame(['users' => true, 'entries' => true, 'audit_log' => true], $this->get('written'));
        $this->assertFalse($this->get('rebuild'));
    }

    public function testReadsAndSessionStatementsChangeNothing(): void
    {
        foreach (['SELECT * FROM "users"', 'SET FOREIGN_KEY_CHECKS = 0', 'SAVEPOINT trans2', 'PRAGMA foreign_keys = ON', 'BEGIN IMMEDIATE'] as $sql) {
            TestDatabase::heard($sql);
        }
        $this->assertSame([[], [], false], [$this->get('written'), $this->get('created'), $this->get('rebuild')]);
    }

    public function testATableOfTheTestsOwnIsDroppedNotRebuilt(): void
    {
        TestDatabase::heard('CREATE TABLE "p_ledger_rows" ("id" INTEGER PRIMARY KEY)');
        TestDatabase::heard('CREATE INDEX "idx_p_ledger_rows_id" ON "p_ledger_rows" ("id")');
        TestDatabase::heard('ALTER TABLE "p_ledger_rows" ADD COLUMN "note" TEXT');
        TestDatabase::heard('INSERT INTO "p_ledger_rows" ("id") VALUES (?)');
        $this->assertSame(['p_ledger_rows' => true], $this->get('created'));
        $this->assertFalse($this->get('rebuild'), 'its indexes and columns are its own');
    }

    public function testChangingCoresTablesRebuildsEverything(): void
    {
        foreach (['ALTER TABLE "users" ADD COLUMN "nickname" TEXT', 'DROP TABLE IF EXISTS "entries"', 'CREATE TABLE "users" ("id" INTEGER)', 'CREATE INDEX "idx_users_x" ON "users" ("x")', 'RENAME TABLE `users` TO `people`'] as $sql) {
            $this->set('rebuild', false);
            TestDatabase::heard($sql);
            $this->assertTrue($this->get('rebuild'), $sql);
        }
    }

    public function testItsOwnStatementsAreNotCounted(): void
    {
        $this->set('quiet', true);
        TestDatabase::heard('TRUNCATE TABLE "users"');
        TestDatabase::heard('DROP TABLE "entries"');
        $this->assertSame([[], false], [$this->get('written'), $this->get('rebuild')]);
    }

    public function testTheSuitesDatabaseIsNeverOneOtherTestsWipe(): void
    {
        $config = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'kestello_test', 'username' => 'kestello', 'password' => 'secret'];
        $others = getenv('KESTELLO_TEST_DATABASES');
        try {
            putenv('KESTELLO_TEST_DATABASE=' . json_encode($config));
            putenv('KESTELLO_TEST_DATABASES=' . json_encode([['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'kestello_test'], $config]));
            $this->assertThrows(\RuntimeException::class, static fn() => TestDatabase::config());

            putenv('KESTELLO_TEST_DATABASES=' . json_encode([['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'kestello_other']]));
            $this->assertSame('kestello_test', TestDatabase::config()['database'] ?? null, 'another database on the same server is fine');

            putenv('KESTELLO_TEST_DATABASE={"driver":"sqlite"}');
            $this->assertThrows(\RuntimeException::class, static fn() => TestDatabase::config(), 'a server database, or none');
            putenv('KESTELLO_TEST_DATABASE=');
            $this->assertNull(TestDatabase::config(), 'none: SQLite in memory');
        } finally {
            putenv($others === false ? 'KESTELLO_TEST_DATABASES' : 'KESTELLO_TEST_DATABASES=' . $others);
        }
    }
}
