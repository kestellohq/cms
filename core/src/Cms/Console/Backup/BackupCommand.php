<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Backup;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Framework\Console\Command;

/** Shared by the backup:* commands. */
abstract class BackupCommand extends Command
{
    protected function backups(): Backups
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');

        return $cms->container->make(Backups::class);
    }

    protected function fail(\Throwable $e): int
    {
        $messages = $e instanceof ContentException && $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()];
        $this->output->error('  ' . implode("\n  ", $messages));

        return 1;
    }

    protected static function size(int $bytes): string
    {
        return $bytes >= 1 << 30 ? round($bytes / (1 << 30), 1) . ' GB' : ($bytes >= 1 << 20 ? round($bytes / (1 << 20), 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB');
    }
}
