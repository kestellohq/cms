<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Backup;

/** backup:delete <name> */
final class BackupDeleteCommand extends BackupCommand
{
    protected string $name = 'backup:delete';
    protected string $description = 'Delete a backup: backup:delete <name>';

    public function handle(array $arguments, array $options): int
    {
        $name = $this->argument($arguments, 0);
        if ($name === null) {
            $this->output->error('  Usage: backup:delete <name>');
            return 1;
        }
        try {
            $this->backups()->delete($name);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $this->output->success("  Deleted {$name}");

        return 0;
    }
}
