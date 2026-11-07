<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Platform;

final class PlatformTestCommand extends PlatformCommand
{
    protected string $name = 'platform:test';
    protected string $description = 'Test a subsystem\'s current or proposed driver: platform:test <subsystem> [driver] [key=value ...]';

    public function handle(array $arguments, array $options): int
    {
        $subsystem = $this->requireSubsystem($this->argument($arguments, 0));
        if ($subsystem === null) {
            return 1;
        }

        $current = $this->manager()->config()->effective($subsystem);
        $driver = $this->argument($arguments, 1) ?? $current['driver'];
        $values = $this->parseOptions(array_slice($arguments, 2));
        if ($values === null) {
            return 1;
        }
        if ($driver === $current['driver'] && $values === []) {
            $values = $current['options'];
        }

        $result = $this->manager()->test($subsystem, $driver, $values);
        if ($result['ok']) {
            $this->output->success("  {$subsystem}/{$driver}: {$result['message']} ({$result['ms']} ms)");
            return 0;
        }

        $this->output->error("  {$subsystem}/{$driver}: {$result['message']}");
        $this->printErrors($result['errors']);

        return 1;
    }
}
