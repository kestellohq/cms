<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Show the status of all migrations.
 */
final class MigrateStatusCommand extends Command
{
    protected string $name = 'migrate:status';
    protected string $description = 'Show the status of each migration';

    public function __construct(
        private readonly string|array $migrationsPath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $runner = new MigrationRunner($this->migrationsPath);
        $status = $runner->status();

        if (empty($status)) {
            $this->output->muted('  No migrations found.');
            return 0;
        }

        $rows = [];
        foreach ($status as $entry) {
            $rows[] = [
                $entry['ran'] ? 'Yes' : 'No',
                $entry['name'],
                $entry['batch'] !== null ? (string) $entry['batch'] : '-',
            ];
        }

        $this->output->newLine();
        $this->output->table(['Ran', 'Migration', 'Batch'], $rows);

        return 0;
    }
}
