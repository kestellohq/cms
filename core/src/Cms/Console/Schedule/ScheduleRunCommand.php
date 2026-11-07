<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

final class ScheduleRunCommand extends ScheduleCommand
{
    protected string $name = 'schedule:run';
    protected string $description = 'Run due scheduled tasks (cron: * * * * * php bin/cms schedule:run)';

    public function handle(array $arguments, array $options): int
    {
        $results = $this->runner()->run(via: 'cron');
        if (!$this->hasFlag($options, 'quiet')) {
            $results === [] ? $this->output->line('  No tasks due.') : $this->report($results);
        }

        return in_array('failed', $results, true) ? 1 : 0;
    }
}
