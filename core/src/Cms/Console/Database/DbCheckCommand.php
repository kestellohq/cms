<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Database;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\SchemaSnapshot;

/**
 * system:db-check: compare this site's tables with what this version of
 * Kestello expects (core/database/schema.json). Exits 1 when a table,
 * column or index is missing; extra ones are listed but are not a problem.
 */
final class DbCheckCommand extends Command
{
    protected string $name = 'system:db-check';

    protected string $description = 'Compare the database with the tables this version of Kestello expects (exit code 1 when something is missing)';

    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        try {
            $diff = SchemaSnapshot::check(DatabaseManager::getConnection(), SchemaSnapshotCommand::file($this->paths));
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());

            return 1;
        }
        foreach ($diff['missing'] as $line) {
            $this->output->error("  missing  {$line}");
        }
        foreach ($diff['extra'] as $line) {
            $this->output->warning("  extra    {$line}");
        }
        if ($diff['missing'] !== []) {
            $this->output->line('  The database is missing what Kestello expects. If migrations are pending, run php bin/cms migrate, then check again.');

            return 1;
        }
        $this->output->success('  The database has every table, column and index Kestello expects (' . $diff['tables'] . ' tables).');

        return 0;
    }
}
