<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

/** Development: stay in the foreground and run the scheduler at the start of every minute. */
final class ScheduleWorkCommand extends ScheduleCommand
{
    protected string $name = 'schedule:work';
    protected string $description = 'Run the scheduler every minute in the foreground (development)';

    public function handle(array $arguments, array $options): int
    {
        $this->output->info('  Running the scheduler every minute. Ctrl+C to stop.');
        while (true) {
            $results = $this->runner()->run(via: 'cli');
            $this->report($results);
            sleep(60 - (int) date('s'));
        }
    }
}
