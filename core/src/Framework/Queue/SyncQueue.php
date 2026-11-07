<?php

declare(strict_types=1);

namespace Kestello\Framework\Queue;

use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;

/**
 * "Run now": $cms->queue executes pushed jobs immediately in the request
 * (delays are ignored). For development and tests. Nothing is stored.
 */
final class SyncQueue implements Queue
{
    public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string
    {
        throw new \LogicException('The sync queue runs jobs directly; push through $cms->queue.');
    }

    public function reserve(array $queues, int $visibility): ?ReservedJob
    {
        return null;
    }
    public function touch(ReservedJob $job, int $visibility): void {}
    public function delete(ReservedJob $job): void {}
    public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void {}
    public function stats(): array
    {
        return [];
    }
    public function clear(?string $queue = null): int
    {
        return 0;
    }
}
