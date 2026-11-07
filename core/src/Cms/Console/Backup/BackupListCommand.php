<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Backup;

/** backup:list */
final class BackupListCommand extends BackupCommand
{
    protected string $name = 'backup:list';
    protected string $description = 'List backups, newest first';

    public function handle(array $arguments, array $options): int
    {
        try {
            $all = $this->backups()->all();
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        if ($all === []) {
            $this->output->muted('  No backups yet. Make one with backup:create.');
            return 0;
        }
        $this->output->table(['Name', 'Made', 'Kind', 'Size', 'Contents'], array_map(static fn(array $b): array => [
            $b['name'], date('Y-m-d H:i', $b['created_at']), $b['kind'], self::size($b['size']), ($b['files'] ? 'database and files' : 'database') . ($b['driver'] !== '' ? " ({$b['driver']})" : ''),
        ], $all));

        return 0;
    }
}
