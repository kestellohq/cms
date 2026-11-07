<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Drop all tables and re-run all migrations.
 */
final class MigrateFreshCommand extends Command
{
    protected string $name = 'migrate:fresh';
    protected string $description = 'Drop all tables and re-run all migrations';

    public function __construct(
        private readonly string|array $migrationsPath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        if (!$this->hasFlag($options, 'force')) {
            $confirmed = $this->output->confirm('This will DROP all tables and re-migrate. Continue?');
            if (!$confirmed) {
                $this->output->muted('  Cancelled.');
                return 0;
            }
        }

        $this->output->info('Dropping all tables...');

        $runner = new MigrationRunner($this->migrationsPath);
        $runner->reset();

        $this->output->newLine();
        $this->output->info('Running migrations...');
        $this->output->newLine();

        $executed = $runner->migrate();

        foreach ($executed as $migration) {
            $this->output->success("  Migrated: {$migration}");
        }

        $this->output->newLine();
        $this->output->success('Done.');

        return 0;
    }
}
