<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use Kestello\Framework\Database\Grammar\MySqlGrammar;
use Kestello\Framework\Database\Grammar\MySqlSchemaGrammar;
use Kestello\Framework\Database\Grammar\PostgresGrammar;
use Kestello\Framework\Database\Grammar\PostgresSchemaGrammar;
use Kestello\Framework\Database\Grammar\QueryGrammar;
use Kestello\Framework\Database\Grammar\SchemaGrammar;
use Kestello\Framework\Database\Grammar\SqliteGrammar;
use Kestello\Framework\Database\Grammar\SqliteSchemaGrammar;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * A lazy PDO connection to MySQL/MariaDB, PostgreSQL or SQLite.
 *
 * Config keys:
 *   driver     mysql (default) | pgsql | sqlite
 *   host, port, database, username, password   (mysql, pgsql)
 *   charset, collation                        (mysql)
 *   schema, sslmode                           (pgsql)
 *   database = absolute file path or ":memory:" (sqlite)
 *
 * @api
 */
final class Connection
{
    public const array DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private ?PDO $pdo = null;

    /** @var list<Closure(string, float): void> */
    private array $listeners = [];
    private ?QueryGrammar $grammar = null;
    private ?SchemaGrammar $schemaGrammar = null;
    private int $transactions = 0;

    /** @var array<string, list<string>> table => columns, cleared on schema changes */
    private array $columnCache = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
        if (!in_array($this->driver(), self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported database driver [{$this->driver()}].");
        }
    }

    /** @param array<string, mixed> $config */
    public static function sqliteMemory(array $config = []): self
    {
        return new self(['driver' => 'sqlite', 'database' => ':memory:'] + $config);
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function grammar(): QueryGrammar
    {
        return $this->grammar ??= match ($this->driver()) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
        };
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return $this->schemaGrammar ??= match ($this->driver()) {
            'mysql' => new MySqlSchemaGrammar($this->grammar()),
            'pgsql' => new PostgresSchemaGrammar($this->grammar()),
            'sqlite' => new SqliteSchemaGrammar($this->grammar()),
        };
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    public function table(string $table): QueryBuilder
    {
        return QueryBuilder::table($table, $this);
    }

    /** @return list<string> */
    public function cachedColumns(string $table): array
    {
        return $this->columnCache[$table] ??= $this->schema()->columns($table);
    }

    /** @internal called by Schema after DDL */
    public function forgetColumns(?string $table = null): void
    {
        if ($table === null) {
            $this->columnCache = [];
        } else {
            unset($this->columnCache[$table]);
        }
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        return $this->pdo;
    }

    // ── Statements ──────────────────────────────────────────────────

    /**
     * Prepare and run a statement with bindings. A connection the server
     * dropped (a long-running worker, a restarted database) is opened again
     * and the statement run once more, outside a transaction only: inside
     * one, what the transaction did is gone with the connection.
     */
    public function query(string $sql, array $bindings = []): PDOStatement
    {
        $started = hrtime(true);
        try {
            $statement = $this->reconnecting(function () use ($sql, $bindings): PDOStatement {
                $statement = $this->getPdo()->prepare($sql);

                foreach (array_values($bindings) as $i => $value) {
                    $statement->bindValue($i + 1, ...self::bindable($value));
                }

                $statement->execute();

                return $statement;
            });
        } finally {
            $this->ran($sql, $started);
        }

        return $statement;
    }

    public function execute(string $sql, array $bindings = []): bool
    {
        $this->query($sql, $bindings);

        return true;
    }

    /** Run SQL without bindings (DDL). Never pass user input. */
    public function raw(string $sql): bool
    {
        $started = hrtime(true);
        try {
            return $this->reconnecting(fn(): bool => $this->getPdo()->exec($sql) !== false);
        } finally {
            $this->ran($sql, $started);
        }
    }

    /**
     * Be told about each statement that ran, whether it worked or failed:
     * its SQL, with placeholders and without the values, and how long it
     * took in milliseconds. Core's
     * QueryWatch logs slow and repeated ones; a tracing plugin can turn
     * them into spans. A listener that throws is ignored.
     *
     * @param Closure(string, float): void $listener
     */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    private function ran(string $sql, int $started): void
    {
        if ($this->listeners === []) {
            return;
        }
        $ms = (hrtime(true) - $started) / 1e6;
        foreach ($this->listeners as $listener) {
            try {
                $listener($sql, $ms);
            } catch (\Throwable) {
                // watching a statement never fails it
            }
        }
    }

    /** Close the connection; the next statement opens a new one. */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactions = 0;
    }

    /**
     * Whether an error means the connection is gone (rather than the
     * statement being wrong): MySQL's "server has gone away" and "lost
     * connection", PostgreSQL's connection errors (SQLSTATE class 08, or the
     * server shutting the session down), and their messages when PDO gives
     * no code.
     */
    public static function lostConnection(\Throwable $e): bool
    {
        $info = $e instanceof PDOException ? ($e->errorInfo ?? []) : [];
        $state = (string) ($info[0] ?? ($e instanceof PDOException ? $e->getCode() : ''));
        $code = (int) ($info[1] ?? 0);
        if (in_array($code, [2006, 2013, 2055, 4031], true) || str_starts_with($state, '08') || in_array($state, ['57P01', '57P02', '57P03'], true)) {
            return true;
        }

        return preg_match('/server has gone away|lost connection|no connection to the server|server closed the connection unexpectedly|connection (?:was )?(?:closed|reset|refused)|SSL connection has been closed|Broken pipe|terminating connection|disconnected by the server/i', $e->getMessage()) === 1;
    }

    /**
     * Whether an error is one the database asks you to retry: a deadlock or
     * a serialization failure (SQLSTATE 40001, 40P01; MySQL 1213, and 1205,
     * a lock wait that timed out).
     */
    public static function retryable(\Throwable $e): bool
    {
        $info = $e instanceof PDOException ? ($e->errorInfo ?? []) : [];
        $state = (string) ($info[0] ?? ($e instanceof PDOException ? $e->getCode() : ''));

        return in_array($state, ['40001', '40P01'], true) || in_array((int) ($info[1] ?? 0), [1213, 1205], true);
    }

    /**
     * @template T
     * @param Closure(): T $run
     * @return T
     */
    private function reconnecting(Closure $run): mixed
    {
        try {
            return $run();
        } catch (PDOException $e) {
            if (!self::lostConnection($e)) {
                throw $e;
            }
            $inTransaction = $this->transactions > 0;
            $this->disconnect();
            if ($inTransaction) {
                throw $e;   // the transaction's work went with the connection: the caller has to start again
            }

            return $run();
        }
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    /**
     * The id generated by the last INSERT. Postgres inserts use RETURNING
     * instead (see QueryBuilder::insert()); this is the fallback.
     */
    public function lastInsertId(): string
    {
        try {
            return (string) $this->getPdo()->lastInsertId();
        } catch (PDOException) {
            return '0'; // Postgres: no sequence used yet in this session
        }
    }

    // ── Transactions (nested ones use savepoints) ───────────────────

    public function beginTransaction(): bool
    {
        if ($this->transactions === 0) {
            if ($this->driver() === 'sqlite') {
                // IMMEDIATE takes the write lock now. A plain (deferred) BEGIN
                // takes it at the first write, so two processes that both read
                // first deadlock on it ("database is locked") instead of the
                // second waiting busy_timeout for the first.
                $this->raw('BEGIN IMMEDIATE');
            } else {
                $this->reconnecting(fn(): bool => $this->getPdo()->beginTransaction());
            }
        } else {
            $this->raw('SAVEPOINT kst_' . $this->transactions);
        }
        $this->transactions++;

        return true;
    }

    public function commit(): bool
    {
        if ($this->transactions === 0) {
            return false;
        }

        if ($this->transactions === 1) {
            // MySQL commits DDL implicitly; PDO then has no transaction to commit.
            try {
                if ($this->driver() === 'sqlite') {
                    $this->getPdo()->exec('COMMIT');
                } elseif ($this->getPdo()->inTransaction()) {
                    $this->getPdo()->commit();
                }
            } catch (\Throwable $e) {
                // A refused commit (deferred constraint, serialization failure)
                // must not leave PDO inside a transaction for the rest of the process.
                $this->transactions = 0;
                $this->rollBackPdo();
                throw $e;
            }
        } else {
            $this->raw('RELEASE SAVEPOINT kst_' . ($this->transactions - 1));
        }
        $this->transactions--;

        return true;
    }

    public function rollback(): bool
    {
        if ($this->transactions === 0) {
            return false;
        }

        $this->transactions--;
        if ($this->transactions === 0) {
            $this->rollBackPdo();
        } else {
            $this->raw('ROLLBACK TO SAVEPOINT kst_' . $this->transactions);
        }

        return true;
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /** End the outermost transaction without saving it, whichever way it was started. */
    private function rollBackPdo(): void
    {
        if ($this->pdo === null) {
            return;   // the connection is gone, and its transaction with it
        }
        if ($this->driver() === 'sqlite') {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (PDOException) {
                // SQLite ends the transaction itself on some errors: nothing left to roll back
            }
        } elseif ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Run $callback in a transaction. With $attempts above 1, an outermost
     * transaction the database refused for a deadlock or serialization
     * failure (retryable()) runs again from the start, after a short pause.
     * Only pass that for a callback that does nothing outside the database
     * (no email, no files): it may run more than once.
     *
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $outermost = $this->transactions === 0;
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            } catch (\Throwable $e) {
                if ($this->transactions > 0) {
                    $this->rollback();
                }
                // a nested transaction's failure belongs to the outer one, which decides
                if (!$outermost || $attempt >= $attempts || !self::retryable($e)) {
                    throw $e;
                }
                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }

    // ── Connecting ──────────────────────────────────────────────────

    private function connect(): void
    {
        $driver = $this->driver();

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException("The PHP extension pdo_{$driver} is not installed.");
        }

        try {
            $this->pdo = new PDO($this->dsn(), $this->config['username'] ?? null, $this->config['password'] ?? null, self::options());
            $this->configureSession();
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }
    }

    private function dsn(?string $database = null): string
    {
        $c = $this->config;
        $database ??= (string) ($c['database'] ?? '');

        return match ($this->driver()) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;%scharset=%s',
                $c['host'] ?? '127.0.0.1',
                (int) ($c['port'] ?? 3306),
                $database !== '' ? 'dbname=' . $database . ';' : '',
                self::safeName((string) ($c['charset'] ?? 'utf8mb4')),
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s%s',
                $c['host'] ?? '127.0.0.1',
                (int) ($c['port'] ?? 5432),
                $database !== '' ? $database : 'postgres',
                isset($c['sslmode']) ? ';sslmode=' . self::safeName((string) $c['sslmode']) : '',
            ),
            'sqlite' => 'sqlite:' . ($database !== '' ? $database : ':memory:'),
        };
    }

    private function configureSession(): void
    {
        $c = $this->config;

        match ($this->driver()) {
            'mysql' => $this->pdo->exec(sprintf(
                "SET NAMES '%s' COLLATE '%s'",
                self::safeName((string) ($c['charset'] ?? 'utf8mb4')),
                self::safeName((string) ($c['collation'] ?? 'utf8mb4_unicode_ci')),
            )),
            'pgsql' => $this->pdo->exec("SET client_encoding = 'UTF8'"
                . (isset($c['schema']) ? '; SET search_path TO ' . $this->grammar()->quoteName((string) $c['schema']) : '')),
            'sqlite' => $this->configureSqlite(),
        };
    }

    private function configureSqlite(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = ' . (int) ($this->config['busy_timeout'] ?? 5000));

        if (($this->config['database'] ?? ':memory:') !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    /** @return array<int, mixed> */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    /**
     * Bind values with their natural types: booleans as 1/0 (every dialect
     * accepts that for boolean columns), dates as UTC-less strings.
     *
     * @return array{0: mixed, 1: int}
     */
    private static function bindable(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value ? 1 : 0, PDO::PARAM_INT],
            is_int($value) => [$value, PDO::PARAM_INT],
            // Stored as UTC, like Support\Date::toDatabase().
            $value instanceof DateTimeInterface => [\DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), PDO::PARAM_STR],
            $value instanceof \Kestello\Support\Date => [$value->toDatabase(), PDO::PARAM_STR],
            $value instanceof \BackedEnum => [$value->value, is_int($value->value) ? PDO::PARAM_INT : PDO::PARAM_STR],
            is_float($value) => [(string) $value, PDO::PARAM_STR],
            default => [(string) $value, PDO::PARAM_STR],
        };
    }

    private static function safeName(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid database option value [{$value}].");
        }

        return $value;
    }

    // ── Installer helpers ───────────────────────────────────────────

    /**
     * Create the configured database if it doesn't exist (mysql, pgsql).
     * SQLite databases are created on first connect.
     */
    public static function createDatabase(array $config): void
    {
        $driver = (string) ($config['driver'] ?? 'mysql');
        $name = (string) ($config['database'] ?? '');

        if ($driver === 'sqlite') {
            $dir = dirname($name);
            if ($name !== ':memory:' && !is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create the directory for the SQLite database: {$dir}");
            }
            return;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Database names may only contain letters, numbers and underscores.');
        }

        $server = new self(['database' => ''] + $config);
        $pdo = new PDO($server->dsn(''), $config['username'] ?? null, $config['password'] ?? null, self::options());

        if ($driver === 'mysql') {
            $charset = self::safeName((string) ($config['charset'] ?? 'utf8mb4'));
            $collation = self::safeName((string) ($config['collation'] ?? 'utf8mb4_unicode_ci'));
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$collation}");
            return;
        }

        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$name]);
        if ($exists->fetchColumn() === false) {
            $pdo->exec('CREATE DATABASE "' . $name . '" ENCODING \'UTF8\'');
        }
    }
}
