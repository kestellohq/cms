<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

use Kestello\Cms\Schedule\ScheduleRunner;

final class ScheduleListCommand extends ScheduleCommand
{
    protected string $name = 'schedule:list';
    protected string $description = 'List scheduled tasks with their next and last runs';

    public function handle(array $arguments, array $options): int
    {
        $rows = $this->runner()->sync(time());
        $out = [];
        foreach ($this->cms()->schedule->tasks() as $name => $task) {
            $row = $rows[$name];
            $last = $row['last_started_at'] !== null
                ? date('m-d H:i', (int) $row['last_started_at']) . ' ' . $row['last_status'] . ($row['last_duration_ms'] !== null ? " ({$row['last_duration_ms']} ms)" : '')
                : '-';
            $out[] = [
                $name,
                $task->expression() . ($task->zone()->getName() !== date_default_timezone_get() ? ' ' . $task->zone()->getName() : ''),
                (bool) $row['disabled'] ? 'disabled' : date('m-d H:i', $task->nextDue(time())),
                $last,
                $task->owner,
            ];
        }
        $this->output->table(['Task', 'Cron', 'Next', 'Last', 'Owner'], $out);

        $beat = \Kestello\Cms\System\CoreTasks::cache($this->cms())->get(ScheduleRunner::HEARTBEAT_KEY);
        $this->output->line(is_array($beat)
            ? '  Scheduler last ran ' . (time() - (int) $beat['at']) . "s ago (via {$beat['via']})."
            : '  The scheduler has not run yet. Add: * * * * * php ' . $this->cms()->paths->root() . '/bin/cms schedule:run');

        return 0;
    }
}
