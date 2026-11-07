<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Queue\SyncQueue;

/** Put failed jobs back on their queue (attempts start over). */
final class QueueRetryCommand extends QueueCommand
{
    protected string $name = 'queue:retry';
    protected string $description = 'Retry failed jobs: queue:retry <id|all>';

    public function handle(array $arguments, array $options): int
    {
        $which = $this->argument($arguments, 0);
        if ($which === null) {
            $this->output->error('  Usage: queue:retry <id|all>');
            return 1;
        }
        $backend = $this->queue()->backend();
        if ($backend instanceof SyncQueue) {
            $this->output->error('  The queue driver is "sync"; switch to a real queue to retry jobs.');
            return 1;
        }

        $query = QueryBuilder::table('failed_jobs');
        if ($which !== 'all') {
            $query->where('id', (int) $which);
        }
        $count = 0;
        foreach ($query->get() as $row) {
            $backend->push((string) $row['queue'], \Kestello\Cms\Api\Endpoints\System\RetryFailedJobs::detachFromBatch((string) $row['payload']));
            QueryBuilder::table('failed_jobs')->where('id', $row['id'])->delete();
            $count++;
        }

        if ($count === 0) {
            $this->output->error($which === 'all' ? '  No failed jobs.' : "  No failed job {$which}.");
            return 1;
        }
        $this->output->success("  Queued {$count} job(s) again.");

        return 0;
    }
}
