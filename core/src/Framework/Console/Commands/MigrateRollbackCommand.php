<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Rollback the last batch of migrations.
 */
final class MigrateRollbackCommand extends Command
{
    protected string $name = 'migrate:rollback';
    protected string $description = 'Rollback the last batch of migrations';

    public function __construct(
        private readonly string|array $migrationsPath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $steps = (int) $this->option($options, 'steps', '1');

        $this->output->info("Rolling back {$steps} batch(es)...");
        $this->output->newLine();

        $runner = new MigrationRunner($this->migrationsPath);
        $rolledBack = $runner->rollback($steps);

        if (empty($rolledBack)) {
            $this->output->muted('  Nothing to rollback.');
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
