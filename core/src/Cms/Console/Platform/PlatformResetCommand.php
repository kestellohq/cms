<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Platform;

final class PlatformResetCommand extends PlatformCommand
{
    protected string $name = 'platform:reset';
    protected string $description = 'Drop admin-made driver settings (back to config files, env and defaults): platform:reset <subsystem|all>';

    public function handle(array $arguments, array $options): int
    {
        $target = $this->argument($arguments, 0);
        $subsystems = $target === 'all'
            ? array_keys($this->manager()->registry()->subsystems())
            : array_filter([$this->requireSubsystem($target)]);

        if ($subsystems === []) {
            return 1;
        }

        foreach ($subsystems as $subsystem) {
            $this->manager()->reset($subsystem);
            $driver = $this->manager()->config()->effective($subsystem)['driver'];
            $this->output->success("  {$subsystem}: reset (now \"{$driver}\").");
        }

        return 0;
    }
}
