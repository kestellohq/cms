<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Platform;

use Kestello\Cms\Platform\PlatformException;

final class PlatformSetCommand extends PlatformCommand
{
    protected string $name = 'platform:set';
    protected string $description = 'Change a driver (tested first): platform:set <subsystem> <driver> [key=value|key=@env:VAR ...] [--skip-test]';

    public function handle(array $arguments, array $options): int
    {
        $subsystem = $this->requireSubsystem($this->argument($arguments, 0));
        $driver = $this->argument($arguments, 1);
        if ($subsystem === null || $driver === null) {
            $driver === null && $subsystem !== null && $this->output->error('Name a driver.');
            return 1;
        }

        $values = $this->parseOptions(array_slice($arguments, 2));
        if ($values === null) {
            return 1;
        }

        try {
            $this->manager()->update($subsystem, $driver, $values, skipTest: isset($options['skip-test']));
        } catch (PlatformException $e) {
            $this->output->error('  ' . $e->getMessage());
            $this->printErrors($e->errors);
            return 1;
        }

        $this->output->success("  {$subsystem} now uses \"{$driver}\".");

        return 0;
    }
}
