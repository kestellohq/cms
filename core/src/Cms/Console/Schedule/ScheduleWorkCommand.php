<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

/** Development: stay in the foreground and run the scheduler at the start of every minute. */
final class ScheduleWorkCommand extends ScheduleCommand
{
    protected string $name = 'schedule:work';
    protected string $description = 'Run the scheduler every minute in the foreground (development)';

    private bool $stop = false;

    public function handle(array $arguments, array $options): int
    {
        $this->trapSignals();
        $this->output->info('  Running the scheduler every minute. Ctrl+C to stop.');
        while (!$this->stop) {
            $this->report($this->runner()->run(via: 'cli'));
            $this->waitForNextMinute();
        }
        $this->output->info('  Stopped.');

        return 0;
    }

    private function waitForNextMinute(): void
    {
        $until = time() + 60 - (int) date('s');
        while (!$this->stop && time() < $until) {
            sleep(1);
        }
    }

    /** Ctrl+C or `docker compose stop` (SIGTERM): finish the current run, then exit. */
    private function trapSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;     // no pcntl: the default action ends the process
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stop = true;
            });
        }
    }
}
