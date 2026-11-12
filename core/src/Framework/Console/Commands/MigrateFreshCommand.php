<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Roll back every core migration (dropping core's tables) and run them again.
 * Plugins' migrations, and their tables, are left as they are.
 */
final class MigrateFreshCommand extends Command
{
    protected string $name = 'migrate:fresh';
    protected string $description = 'Roll back and re-run core\'s migrations (plugin tables stay)';

    public function __construct(
        private readonly string|array $migrationsPath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        if (!$this->hasFlag($options, 'force')) {
            $confirmed = $this->output->confirm('This rolls back every core migration, dropping core\'s tables, and runs them again. Continue?');
            if (!$confirmed) {
                $this->output->muted('  Cancelled.');
                return 0;
            }
        }

        $this->output->info('Rolling back core\'s migrations...');

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
