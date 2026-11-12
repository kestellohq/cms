<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Database\Connection;
use Kestello\Framework\Testing\TestCase;
use PDOException;

/**
 * What a connection does when the database drops it, refuses a transaction
 * for a deadlock, or is shared by several processes (SQLite: web, worker and
 * scheduler on one file). The dropped-connection tests need MySQL or
 * PostgreSQL from KESTELLO_TEST_DATABASES (CI has both); the rest run anywhere.
 */
final class ConnectionRecoveryTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function servers(): array
    {
        $servers = [];
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($extra) ? $extra : [] as $config) {
            $servers[$config['driver'] . '@' . ($config['host'] ?? '')] = $config;
        }

        return $servers;
    }

    /** This connection's id on the server, and the statement that ends it from another connection. */
    private static function session(Connection $c): array
    {
        return $c->driver() === 'mysql'
            ? [(int) $c->select('SELECT CONNECTION_ID() AS id')[0]['id'], 'KILL %d']
            : [(int) $c->select('SELECT pg_backend_pid() AS id')[0]['id'], 'SELECT pg_terminate_backend(%d)'];
    }

    private static function drop(Connection $victim, array $config): int
    {
        [$id, $kill] = self::session($victim);
        $other = new Connection($config);
        $other->raw(sprintf($kill, $id));
        $other->disconnect();
        usleep(100_000);   // the server closes the socket

        return $id;
    }

    private static function pdoError(string $state, int $code, string $message): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = [$state, $code, $message];

        return $e;
    }

    public function testErrorsAreToldApartByWhatTheyMean(): void
    {
        $this->assertTrue(Connection::lostConnection(self::pdoError('HY000', 2006, 'MySQL server has gone away')));
        $this->assertTrue(Connection::lostConnection(self::pdoError('HY000', 2013, 'Lost connection to MySQL server during query')));
        $this->assertTrue(Connection::lostConnection(self::pdoError('08006', 7, 'server closed the connection unexpectedly')));
        $this->assertTrue(Connection::lostConnection(self::pdoError('57P01', 7, 'terminating connection due to administrator command')));
        $this->assertTrue(Connection::lostConnection(new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')));
        $this->assertFalse(Connection::lostConnection(self::pdoError('42S02', 1146, "Table 'x' doesn't exist")), 'a wrong statement is not retried');
        $this->assertFalse(Connection::lostConnection(self::pdoError('23000', 1062, 'Duplicate entry')));

        $this->assertTrue(Connection::retryable(self::pdoError('40001', 1213, 'Deadlock found when trying to get lock')));
        $this->assertTrue(Connection::retryable(self::pdoError('40P01', 7, 'deadlock detected')));
        $this->assertTrue(Connection::retryable(self::pdoError('40001', 7, 'could not serialize access')));
        $this->assertTrue(Connection::retryable(self::pdoError('HY000', 1205, 'Lock wait timeout exceeded')));
        $this->assertFalse(Connection::retryable(self::pdoError('23000', 1062, 'Duplicate entry')));
        $this->assertFalse(Connection::retryable(new \RuntimeException('not a database error')));
    }

    public function testADroppedConnectionIsOpenedAgain(): void
    {
        // without MySQL or PostgreSQL in KESTELLO_TEST_DATABASES there is nothing to drop (CI has both)
        foreach (self::servers() as $name => $config) {
            $c = new Connection($config);
            $before = self::drop($c, $config);
            $this->assertSame(1, (int) $c->select('SELECT 1 AS one')[0]['one'], "{$name}: the next statement works");
            $this->assertNotSame($before, self::session($c)[0], "{$name}: on a new connection");
            $c->disconnect();
        }
    }

    public function testAConnectionDroppedInsideATransactionIsReportedNotHidden(): void
    {
        // without MySQL or PostgreSQL in KESTELLO_TEST_DATABASES there is nothing to drop (CI has both)
        foreach (self::servers() as $name => $config) {
            $c = new Connection($config);
            $c->beginTransaction();
            self::drop($c, $config);
            // what the transaction did went with the connection: running on would save half of it
            $this->assertThrows(PDOException::class, fn() => $c->select('SELECT 1 AS one'), "{$name}: the caller hears about it");
            $this->assertSame(0, $c->transactionLevel(), "{$name}: no transaction is left open");
            $this->assertSame(1, (int) $c->select('SELECT 1 AS one')[0]['one'], "{$name}: and the connection works again");
            $c->disconnect();
        }
    }

    public function testATransactionTheDatabaseRefusedRunsAgainWhenAsked(): void
    {
        $c = Connection::sqliteMemory();
        $c->raw('CREATE TABLE t (n INTEGER)');
        $runs = 0;
        $result = $c->transaction(function (Connection $c) use (&$runs): string {
            $runs++;
            $c->raw("INSERT INTO t (n) VALUES ({$runs})");
            if ($runs < 3) {
                throw self::pdoError('40001', 1213, 'Deadlock found when trying to get lock');
            }

            return 'saved';
        }, attempts: 3);
        $this->assertSame('saved', $result);
        $this->assertSame(3, $runs);
        $this->assertSame([['n' => 3]], $c->select('SELECT n FROM t'), 'the failed runs left nothing behind');
        $this->assertSame(0, $c->transactionLevel());

        $this->assertSame(1, self::runsUntilThrown($c, self::pdoError('40001', 1213, 'Deadlock found when trying to get lock'), 1), 'without attempts it runs once: the callback may do things outside the database');
        $this->assertSame(1, self::runsUntilThrown($c, self::pdoError('23000', 1062, 'Duplicate entry'), 3), 'other errors are not retried');
        $this->assertSame(3, self::runsUntilThrown($c, self::pdoError('40001', 1213, 'Deadlock found when trying to get lock'), 3), 'and it gives up after the last attempt');
    }

    /** How many times a transaction whose callback always throws $e ran before the error reached the caller. */
    private static function runsUntilThrown(Connection $c, \Throwable $e, int $attempts): int
    {
        $runs = 0;
        try {
            $c->transaction(function () use (&$runs, $e): void {
                $runs++;
                throw $e;
            }, $attempts);
        } catch (\Throwable $thrown) {
            if ($thrown !== $e) {
                throw $thrown;
            }
        }

        return $runs;
    }

    public function testOnlyTheOutermostTransactionRetries(): void
    {
        $c = Connection::sqliteMemory();
        $inner = 0;
        $outer = 0;
        $c->transaction(function (Connection $c) use (&$inner, &$outer): void {
            $outer++;
            try {
                $c->transaction(function () use (&$inner): void {
                    $inner++;
                    throw self::pdoError('40001', 1213, 'Deadlock found when trying to get lock');
                }, attempts: 5);
            } catch (PDOException) {
                // the outer transaction decides
            }
        }, attempts: 5);
        $this->assertSame(1, $inner, 'a savepoint inside a refused transaction cannot be retried on its own');
        $this->assertSame(1, $outer);
        $this->assertSame(0, $c->transactionLevel());
    }

    public function testSqliteTransactionsTakeTheWriteLockWhenTheyStart(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kst-lock-') . '.sqlite';
        try {
            $a = new Connection(['driver' => 'sqlite', 'database' => $file]);
            $b = new Connection(['driver' => 'sqlite', 'database' => $file, 'busy_timeout' => 0]);
            $a->raw('CREATE TABLE t (n INTEGER)');

            $a->beginTransaction();
            $a->select('SELECT COUNT(*) AS n FROM t');   // only a read so far
            // A deferred BEGIN would let b in here; then both upgrade to write and one fails
            // with "database is locked" after its work. IMMEDIATE makes b wait (here: fail at once).
            $this->assertThrows(PDOException::class, fn() => $b->beginTransaction());
            $this->assertSame(0, $b->transactionLevel(), 'b did not start');
            $a->raw('INSERT INTO t (n) VALUES (1)');
            $a->commit();

            $b->transaction(fn(Connection $b) => $b->raw('INSERT INTO t (n) VALUES (2)'));
            $this->assertSame(2, (int) $a->select('SELECT COUNT(*) AS n FROM t')[0]['n'], 'both saved, in turn');

            // savepoints and rollbacks still work on the explicit transaction
            $a->transaction(function (Connection $a): void {
                $a->raw('INSERT INTO t (n) VALUES (3)');
                try {
                    $a->transaction(function (Connection $a): void {
                        $a->raw('INSERT INTO t (n) VALUES (4)');
                        throw new \RuntimeException('undo the inner one');
                    });
                } catch (\RuntimeException) {
                }
            });
            $this->assertSame([1, 2, 3], array_map('intval', array_column($a->select('SELECT n FROM t ORDER BY n'), 'n')));
            $a->disconnect();
            $b->disconnect();
        } finally {
            foreach ([$file, "{$file}-wal", "{$file}-shm", substr($file, 0, -7)] as $f) {
                @unlink($f);
            }
        }
    }
}
