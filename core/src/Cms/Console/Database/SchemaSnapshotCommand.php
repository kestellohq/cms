<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Database;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Database\SchemaSnapshot;

/**
 * schema:snapshot [--check]
 *
 * Writes core/database/schema.json: the tables core's migrations make on a
 * new database (SchemaSnapshot). Run it after adding a migration. --check
 * writes nothing and fails when the committed file is out of date (CI).
 * Works on a checkout that was never installed.
 */
final class SchemaSnapshotCommand extends Command
{
    /**
     * What only one database has (the snapshot is made on SQLite): the search
     * engine's full-text index. SchemaSnapshotTest fails when this is out of
     * date on MySQL or PostgreSQL.
     */
    public const array ONLY = [
        'sqlite' => ['table search_documents_fts'],
        'pgsql' => ['column search_documents.vector'],
    ];

    protected string $name = 'schema:snapshot';

    protected string $description = 'Write core/database/schema.json from core\'s migrations: schema:snapshot [--check]';

    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        $file = self::file($this->paths);
        try {
            $tables = SchemaSnapshot::fresh($this->paths->coreMigrations());
        } catch (\Throwable $e) {
            $this->output->error('  The migrations failed on a new database: ' . $e->getMessage());

            return 1;
        }
        $json = SchemaSnapshot::toJson($tables, self::ONLY);

        if (isset($options['check'])) {
            if (!is_file($file) || file_get_contents($file) !== $json) {
                $this->output->error("  {$file} is out of date with the migrations. Run: php bin/cms schema:snapshot");

                return 1;
            }
            $this->output->success("  {$file} is up to date (" . count($tables) . ' tables).');

            return 0;
        }
        if (file_put_contents($file, $json) === false) {
            $this->output->error("  Could not write {$file}");

            return 1;
        }
        $this->output->success("  Wrote {$file} (" . count($tables) . ' tables).');

        return 0;
    }

    public static function file(Paths $paths): string
    {
        return dirname($paths->coreMigrations()) . '/schema.json';
    }
}
