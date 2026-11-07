<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Backup;

/** backup:create [--no-files] [--note=…] */
final class BackupCreateCommand extends BackupCommand
{
    protected string $name = 'backup:create';
    protected string $description = 'Back up the database and files (--no-files: database only, --note=…)';

    public function handle(array $arguments, array $options): int
    {
        try {
            $b = $this->backups()->create(['files' => !$this->hasFlag($options, 'no-files'), 'note' => (string) $this->option($options, 'note', '')]);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $this->output->success("  {$b['name']} (" . self::size($b['size']) . ", {$b['rows']} rows" . ($b['files'] ? ', with files' : ', database only') . ')');

        return 0;
    }
}
