<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Run pending database migrations.
 */
final class MigrateCommand extends Command
{
    protected string $name = 'migrate';
    protected string $description = 'Run all pending database migrations';

    public function __construct(
        private readonly string|array $migrationsPath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $this->output->info('Running migrations...');
        $this->output->newLine();

        $runner = new MigrationRunner($this->migrationsPath);
        $executed = $runner->migrate();

        if (empty($executed)) {
            $this->output->muted('  Nothing to migrate.');
        } else {
            foreach ($executed as $migration) {
                $this->output->success("  Migrated: {$migration}");
            }
        }

        $this->output->newLine();
        $this->output->success('Done.');

        return 0;
    }
}
