<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Storage;

use Kestello\Cms\Cms;
use Kestello\Framework\Console\Command;

final class StorageDisksCommand extends Command
{
    protected string $name = 'storage:disks';
    protected string $description = 'List storage disks and where they point';

    public function handle(array $arguments, array $options): int
    {
        $storage = (Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.'))->storage;
        foreach ($storage->names() as $name) {
            $c = (array) $storage->config($name);
            $driver = (string) ($c['driver'] ?? 'local');
            $where = match ($driver) {
                'local' => (string) ($c['root'] ?? $name),
                's3' => ($c['endpoint'] ?? 'aws') . ' / ' . ($c['bucket'] ?? '?'),
                'scoped' => ($c['disk'] ?? '?') . ':' . ($c['prefix'] ?? ''),
                'readonly', 'cached' => (string) ($c['disk'] ?? '?'),
                'mirror' => implode(' + ', array_map('strval', (array) ($c['disks'] ?? []))),
                default => '',
            };
            $url = isset($c['url']) ? '  ' . $c['url'] : '';
            $owner = ($c['_owner'] ?? 'core') === 'core' ? '' : '  (' . $c['_owner'] . ')';
            $this->output->line(sprintf('  %-16s %-9s %s%s%s', $name, $driver, $where, $url, $owner));
        }

        return 0;
    }
}
