<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Platform;

final class PlatformShowCommand extends PlatformCommand
{
    protected string $name = 'platform:show';
    protected string $description = 'Show platform drivers and where each setting comes from: platform:show [subsystem] [--json]';

    public function handle(array $arguments, array $options): int
    {
        $subsystem = $this->argument($arguments, 0);
        if ($subsystem !== null && $this->requireSubsystem($subsystem) === null) {
            return 1;
        }

        $data = $subsystem !== null ? [$this->manager()->describe($subsystem)] : $this->manager()->describeAll();

        if (isset($options['json'])) {
            foreach ($data as &$d) {
                unset($d['drivers']);
            }
            $this->output->line(json_encode($subsystem !== null ? $data[0] : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        if ($data !== [] && $data[0]['locked']) {
            $this->output->warning('  Platform settings are locked by the host; the admin screen is read-only.');
        }

        foreach ($data as $s) {
            $this->output->heading("{$s['label']} ({$s['name']})");
            $this->output->label('driver', "{$s['driver']}   [{$s['driverSource']}]");
            foreach ($s['options'] as $key => $value) {
                $shown = is_array($value) && array_key_exists('set', $value)
                    ? ($value['set'] ? '•••••• (set)' : '(not set)')
                    : (is_bool($value) ? ($value ? 'true' : 'false') : (string) json_encode($value, JSON_UNESCAPED_SLASHES));
                $this->output->label("  {$key}", $shown . '   [' . ($s['sources'][$key] ?? 'default') . ']');
            }
            if ($s['degraded'] !== null) {
                $this->output->warning("  Running on fallback \"{$s['degraded']['driver']}\": {$s['degraded']['error']}");
            }
            if ($s['errors'] !== []) {
                $this->output->error('  Configuration problems:');
                $this->printErrors($s['errors']);
            }
            $available = array_map(
                static fn(array $d) => $d['name'] . ($d['available'] ? '' : ' (unavailable: ' . $d['unavailableReason'] . ')'),
                $s['drivers'],
            );
            $this->output->muted('  available: ' . implode(', ', $available));
        }

        return 0;
    }
}
