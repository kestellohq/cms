<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

/** schedule:disable / schedule:enable <name> — an override that survives deploys (plugin tasks too). */
final class ScheduleToggleCommand extends ScheduleCommand
{
    public function __construct(private readonly bool $disable)
    {
        $this->name = $disable ? 'schedule:disable' : 'schedule:enable';
        $this->description = ($disable ? 'Stop' : 'Resume') . ' a scheduled task: ' . $this->name . ' <name>';
    }

    public function handle(array $arguments, array $options): int
    {
        $name = $this->argument($arguments, 0);
        if ($name === null || $this->cms()->schedule->get($name) === null) {
            $this->output->error("  Usage: {$this->name} <name>   (see schedule:list)");
            return 1;
        }
        $this->runner()->setDisabled($name, $this->disable);
        $this->output->success('  ' . ($this->disable ? 'Disabled' : 'Enabled') . " {$name}.");

        return 0;
    }
}
