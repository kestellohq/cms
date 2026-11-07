<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

use Kestello\Framework\Database\QueryBuilder;

final class ScheduleTestCommand extends ScheduleCommand
{
    protected string $name = 'schedule:test';
    protected string $description = 'Run one scheduled task now: schedule:test <name>';

    public function handle(array $arguments, array $options): int
    {
        $name = $this->argument($arguments, 0);
        if ($name === null || $this->cms()->schedule->get($name) === null) {
            $this->output->error('  Usage: schedule:test <name>   (see schedule:list)');
            return 1;
        }

        $status = $this->runner()->runNow($name);
        $row = QueryBuilder::table('scheduled_tasks')->where('name', $name)->first();
        $this->report([$name => $status]);
        if (trim((string) ($row['last_output'] ?? '')) !== '') {
            $this->output->line('  ' . str_replace("\n", "\n  ", trim((string) $row['last_output'])));
        }

        return $status === 'failed' ? 1 : 0;
    }
}
