<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Queue\Worker;
use Kestello\Framework\Queue\SyncQueue;

final class QueueWorkCommand extends QueueCommand
{
    protected string $name = 'queue:work';
    protected string $description = 'Run queued jobs [--queue=high,default,mail,media,low] [--stop-when-empty] [--max-jobs=N] [--max-time=S] [--memory=128] [--sleep=3]';

    public function handle(array $arguments, array $options): int
    {
        $queue = $this->queue();
        if ($queue->backend() instanceof SyncQueue) {
            $this->output->warning('  The queue driver is "sync": jobs run when pushed, there is nothing to work on.');
            return 0;
        }

        $queues = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option($options, 'queue') ?? implode(',', JobQueue::QUEUES))))));
        $quiet = $this->hasFlag($options, 'quiet');
        $worker = new Worker($queue, $this->cache(), $quiet ? null : fn(string $line) => $this->output->line('  ' . date('H:i:s') . ' ' . $line));

        $reason = $worker->run(
            queues: $queues,
            stopWhenEmpty: $this->hasFlag($options, 'stop-when-empty'),
            maxJobs: (int) ($this->option($options, 'max-jobs') ?? 0),
            maxTime: (int) ($this->option($options, 'max-time') ?? 0),
            memoryMb: (int) ($this->option($options, 'memory') ?? 128),
            sleep: max(1, (int) ($this->option($options, 'sleep') ?? 3)),
        );

        if (!$quiet) {
            ['done' => $d, 'retry' => $r, 'failed' => $f, 'released' => $l, 'skipped' => $s] = $worker->counts;
            $extra = ($l > 0 ? ", {$l} postponed" : '') . ($s > 0 ? ", {$s} skipped (cancelled batch)" : '');
            $this->output->info("  Stopped ({$reason}): {$d} done, {$r} to retry, {$f} failed{$extra}.");
        }

        return 0;
    }
}
