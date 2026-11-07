<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Cms\Queue\Worker;
use Kestello\Framework\Database\QueryBuilder;

final class QueueMonitorCommand extends QueueCommand
{
    protected string $name = 'queue:monitor';
    protected string $description = 'Show queue sizes, the last worker heartbeat and failed jobs';

    public function handle(array $arguments, array $options): int
    {
        $rows = [];
        foreach ($this->queue()->backend()->stats() as $name => $s) {
            $rows[] = [$name, $s['pending'], $s['delayed'], $s['reserved'], $s['oldest'] === null ? '-' : (time() - $s['oldest']) . 's ago'];
        }
        if ($rows === []) {
            $this->output->info('  No jobs waiting.');
        } else {
            $this->output->table(['Queue', 'Pending', 'Delayed', 'Running', 'Oldest pending'], $rows);
        }

        $beat = $this->cache()->get(Worker::HEARTBEAT_KEY);
        $this->output->line(is_array($beat)
            ? '  Last worker heartbeat: ' . (time() - (int) $beat['at']) . "s ago (pid {$beat['pid']} on {$beat['host']})"
            : '  No worker has been seen.');
        $this->output->line('  Failed jobs: ' . QueryBuilder::table('failed_jobs')->count());

        return 0;
    }
}
