<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Reset all migrations (rollback everything).
 */
final class MigrateResetCommand extends Command
{
    protected string $name = 'migrate:reset';
    protected string $description = 'Rollback all migrations';

    public function __construct(
        private readonly string|array $migrationsPath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        if (!$this->hasFlag($options, 'force')) {
            $confirmed = $this->output->confirm('This will rollback ALL migrations. Continue?');
            if (!$confirmed) {
                $this->output->muted('  Cancelled.');
                return 0;
            }
        }

        $this->output->info('Resetting all migrations...');
        $this->output->newLine();

        $runner = new MigrationRunner($this->migrationsPath);
        $rolledBack = $runner->reset();

        if (empty($rolledBack)) {
            $this->output->muted('  Nothing to reset.');
        } else {
            foreach ($rolledBack as $migration) {
                $this->output->warning("  Rolled back: {$migration}");
            }
        }

        $this->output->newLine();
        $this->output->success('Done.');

        return 0;
    }
}
