<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

/**
 * Runs database migrations and tracks their status.
 */
final class MigrationRunner
{
    private readonly Connection $connection;
    private readonly Schema $schema;

    /** @var list<string> Migration directories, in run order (core first, then site, then plugins). */
    private readonly array $paths;

    /** @var array<string, Migration|string> absolute path => loaded instance or class name (process-wide). */
    private static array $loaded = [];

    /** @var array<string, string>|null filename => absolute path, built lazily. */
    private ?array $files = null;

    /**
     * @param string|list<string> $migrationsPath One directory or several. Filenames must be
     *                                            unique across all directories; they are what
     *                                            the migrations table records.
     * @param string      $owner       Who owns these migrations ("core", "plugin:acme-seo").
     *                                 Rollback/reset/status only ever touch this owner's rows.
     * @param string|null $tablePrefix When set, migrations may only create/alter/drop tables
     *                                 with this prefix (plugins: "p_<slug>_").
     */
    public function __construct(
        string|array $migrationsPath,
        private readonly string $owner = 'core',
        private readonly ?string $tablePrefix = null,
    ) {
        $this->paths = array_values((array) $migrationsPath);
        $this->connection = DatabaseManager::getConnection();
        $this->schema = new Schema($this->connection);
    }

    /**
     * Run all pending migrations, or only the pending ones named in $only
     * (a restore rebuilds the schema a backup was taken at).
     *
     * @param list<string>|null $only migration file names
     * @return array<string> Names of migrations that were run
     */
    public function migrate(?array $only = null): array
    {
        if ($this->files() === []) {
            return []; // nothing to run: don't touch the database
        }

        return $this->locked(function () use ($only): array {
            $this->ensureMigrationsTable();

            // read inside the lock: another process may have just run them
            $ran = $this->getRanMigrations();
            $pending = $this->getPendingMigrations($ran);
            if ($only !== null) {
                $pending = array_intersect_key($pending, array_flip($only));
            }
            $batch = $this->getNextBatch();
            $executed = [];

            foreach ($pending as $file => $className) {
                $migration = $this->load($file, $className);
                $run = function () use ($migration, $file, $batch): void {
                    Schema::restrictTo($this->tablePrefix, static fn() => $migration->up());
                    $this->connection->query(
                        'INSERT INTO migrations (migration, batch, owner) VALUES (?, ?, ?)',
                        [$file, $batch, $this->owner],
                    );
                };
                // PostgreSQL and SQLite change tables inside transactions: a migration that fails halfway leaves nothing behind.
                // MySQL commits each table change by itself, so there it runs as it is.
                in_array($this->connection->driver(), ['pgsql', 'sqlite'], true) && $this->connection->transactionLevel() === 0
                    ? $this->connection->transaction($run)
                    : $run();

                $executed[] = $file;
            }

            return $executed;
        });
    }

    /**
     * Run $work holding a database-wide lock, so two processes (a deploy and
     * a web request finishing an update) never run the same migration twice.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $driver = $this->connection->driver();
        if ($driver === 'mysql') {
            $this->connection->select("SELECT GET_LOCK('kestello_migrations', 300) AS l");
            try {
                return $work();
            } finally {
                $this->connection->select("SELECT RELEASE_LOCK('kestello_migrations') AS l");
            }
        }
        if ($driver === 'pgsql') {
            $this->connection->select('SELECT pg_advisory_lock(728391) AS l');
            try {
                return $work();
            } finally {
                $this->connection->select('SELECT pg_advisory_unlock(728391) AS l');
            }
        }

        return $work();     // SQLite: one writer at a time already
    }

    /**
     * Rollback the last batch of migrations.
     *
     * @return array<string> Names of migrations that were rolled back
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationsTable();

        $rolledBack = [];

        for ($i = 0; $i < $steps; $i++) {
            $batch = $this->getLastBatch($this->owner);

            if ($batch === 0) {
                break;
            }

            $migrations = $this->connection->query(
                'SELECT migration FROM migrations WHERE batch = ? AND owner = ? ORDER BY id DESC',
                [$batch, $this->owner],
            )->fetchAll();

            foreach ($migrations as $row) {
                $file = $row['migration'];
                $className = $this->getClassNameFromFile($file);

                $migration = $this->load($file, $className);
                Schema::restrictTo($this->tablePrefix, static fn() => $migration->down());

                $this->connection->query('DELETE FROM migrations WHERE migration = ? AND owner = ?', [$file, $this->owner]);
                $rolledBack[] = $file;
            }
        }

        return $rolledBack;
    }

    /**
     * Reset all migrations.
     *
     * @return array<string> Names of migrations that were rolled back
     */
    public function reset(): array
    {
        $this->ensureMigrationsTable();

        $migrations = $this->connection->query(
            'SELECT migration FROM migrations WHERE owner = ? ORDER BY id DESC',
            [$this->owner],
        )->fetchAll();

        $rolledBack = [];

        foreach ($migrations as $row) {
            $file = $row['migration'];
            $className = $this->getClassNameFromFile($file);

            $migration = $this->load($file, $className);
            Schema::restrictTo($this->tablePrefix, static fn() => $migration->down());

            $this->connection->query('DELETE FROM migrations WHERE migration = ? AND owner = ?', [$file, $this->owner]);
            $rolledBack[] = $file;
        }

        return $rolledBack;
    }

    /**
     * Get the status of all migrations.
     *
     * @return array<array{name: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $ran = [];
        $rows = $this->connection->query(
            'SELECT migration, batch FROM migrations WHERE owner = ? ORDER BY id',
            [$this->owner],
        )->fetchAll();
        foreach ($rows as $row) {
            $ran[$row['migration']] = (int) $row['batch'];
        }

        $files = $this->getMigrationFiles();
        $status = [];

        foreach ($files as $file => $className) {
            $status[] = [
                'name' => $file,
                'ran' => isset($ran[$file]),
                'batch' => $ran[$file] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    public function ensureMigrationsTable(): void
    {
        if (!$this->schema->hasTable('migrations')) {
            Schema::restrictTo(null, fn() => $this->schema->create('migrations', function (Blueprint $table): void {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
                $table->string('owner', 100)->default('core');
            }));
            return;
        }

        // Tables created before migration ownership existed.
        if (!$this->schema->hasColumn('migrations', 'owner')) {
            Schema::restrictTo(null, fn() => $this->schema->table('migrations', function (Blueprint $table): void {
                $table->string('owner', 100)->default('core');
            }));
        }
    }

    /**
     * Get all migration files from the migrations directory.
     *
     * @return array<string, string> filename => fully qualified class name
     */
    private function getMigrationFiles(): array
    {
        $migrations = [];

        foreach (array_keys($this->files()) as $file) {
            $migrations[$file] = $this->getClassNameFromFile($file);
        }

        return $migrations;
    }

    /**
     * @return array<string, string> filename => absolute path, ordered by directory then filename.
     */
    private function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $this->files = [];

        foreach ($this->paths as $dir) {
            $found = glob(rtrim($dir, '/') . '/*.php') ?: [];
            sort($found);

            foreach ($found as $path) {
                $file = basename($path);

                if (isset($this->files[$file])) {
                    throw new \RuntimeException("Duplicate migration filename \"{$file}\" in {$dir}.");
                }

                $this->files[$file] = $path;
            }
        }

        return $this->files;
    }

    private function pathFor(string $file): string
    {
        return $this->files()[$file]
            ?? throw new \RuntimeException("Migration file \"{$file}\" not found in any migration directory.");
    }

    /**
     * Get the list of already-ran migration filenames.
     *
     * @return array<string>
     */
    private function getRanMigrations(): array
    {
        $rows = $this->connection->query('SELECT migration FROM migrations WHERE owner = ?', [$this->owner])->fetchAll();

        return array_column($rows, 'migration');
    }

    /**
     * Get pending migrations that haven't been run yet.
     *
     * @return array<string, string>
     */
    private function getPendingMigrations(array $ran): array
    {
        $all = $this->getMigrationFiles();

        return array_diff_key($all, array_flip($ran));
    }

    /**
     * Get the next batch number.
     */
    private function getNextBatch(): int
    {
        return $this->getLastBatch() + 1;
    }

    /**
     * Get the last batch number.
     */
    /**
     * Highest batch number, overall (for numbering new batches) or for one owner (for rollback).
     */
    private function getLastBatch(?string $owner = null): int
    {
        $result = $owner === null
            ? $this->connection->query('SELECT MAX(batch) as batch FROM migrations')->fetch()
            : $this->connection->query('SELECT MAX(batch) as batch FROM migrations WHERE owner = ?', [$owner])->fetch();

        return (int) ($result['batch'] ?? 0);
    }

    /**
     * Load a migration. Files may `return new class extends Migration { … };`
     * (recommended for plugins: no class-name collisions between packages),
     * or declare a class named after the file.
     */
    private function load(string $file, string $className): Migration
    {
        $path = $this->pathFor($file);

        // Each file is included once per process; remember what it produced
        // (an instance for anonymous migrations, a class name otherwise).
        if (!array_key_exists($path, self::$loaded)) {
            $result = (static fn(string $__path): mixed => require $__path)($path);
            self::$loaded[$path] = $result instanceof Migration ? $result : $className;
        }

        $loaded = self::$loaded[$path];

        if ($loaded instanceof Migration) {
            return $loaded;
        }

        if (!class_exists($loaded, false)) {
            throw new \RuntimeException("Migration \"{$file}\" must return a Migration instance or declare class {$loaded}.");
        }

        return new $loaded();
    }

    /**
     * Derive a class name from a migration filename.
     * Convention: 2024_01_15_000001_create_users_table.php -> CreateUsersTable
     */
    private function getClassNameFromFile(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        // Remove timestamp prefix (e.g., "2024_01_15_000001_")
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);

        // Convert snake_case to PascalCase
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
