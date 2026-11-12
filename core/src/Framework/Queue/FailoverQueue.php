<?php

declare(strict_types=1);

namespace Kestello\Framework\Queue;

use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;
use Kestello\Framework\Log\Log;
use Throwable;
use WeakMap;

/**
 * A queue (Redis) backed by a second one (the database): when the first
 * can't take a job, the job goes to the second instead of being lost, and
 * workers take jobs from both. Each reserved job is finished on the queue
 * it came from.
 */
final class FailoverQueue implements Queue
{
    /** @var WeakMap<ReservedJob, Queue> */
    private WeakMap $origin;

    public function __construct(private readonly Queue $primary, private readonly Queue $secondary)
    {
        $this->origin = new WeakMap();
    }

    public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string
    {
        try {
            return $this->primary->push($queue, $payload, $delay, $uniqueKey);
        } catch (Throwable $e) {
            Log::warning('queue: the primary queue refused a job, it went to the database instead: ' . $e->getMessage());

            return $this->secondary->push($queue, $payload, $delay, $uniqueKey);
        }
    }

    public function reserve(array $queues, int $visibility): ?ReservedJob
    {
        foreach ([$this->primary, $this->secondary] as $backend) {
            try {
                $job = $backend->reserve($queues, $visibility);
            } catch (Throwable $e) {
                Log::error('queue: cannot read a queue: ' . $e->getMessage());
                continue;
            }
            if ($job !== null) {
                $this->origin[$job] = $backend;

                return $job;
            }
        }

        return null;
    }

    public function touch(ReservedJob $job, int $visibility): void
    {
        $this->of($job)->touch($job, $visibility);
    }

    public function delete(ReservedJob $job): void
    {
        $this->of($job)->delete($job);
    }

    public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void
    {
        $this->of($job)->release($job, $delay, $refund);
    }

    public function stats(): array
    {
        try {
            return $this->primary->stats();
        } catch (Throwable) {
            return $this->secondary->stats();
        }
    }

    public function clear(?string $queue = null): int
    {
        $cleared = $this->secondary->clear($queue);
        try {
            $cleared += $this->primary->clear($queue);
        } catch (Throwable) {
        }

        return $cleared;
    }

    /** The queue in front (for health checks: a fallback that works must not hide it). */
    public function primary(): Queue
    {
        return $this->primary;
    }

    private function of(ReservedJob $job): Queue
    {
        return $this->origin[$job] ?? $this->primary;
    }
}
