<?php

declare(strict_types=1);

namespace Kestello\Contracts\Queue;

/**
 * Queue storage (the "queue" platform subsystem). Drivers only move opaque
 * payloads; building and running jobs is $cms->queue's business.
 *
 * @api
 */
interface Queue
{
    /** Store a payload. Returns the id, or null when $uniqueKey is already pending. */
    public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string;

    /**
     * Take the next available job from the first non-empty queue in $queues
     * (priority order). It is hidden from other workers for $visibility seconds.
     *
     * @param list<string> $queues
     */
    public function reserve(array $queues, int $visibility): ?ReservedJob;

    /** Keep holding it: hidden from other workers for $visibility more seconds. */
    public function touch(ReservedJob $job, int $visibility): void;

    /** Done: remove it. */
    public function delete(ReservedJob $job): void;

    /**
     * Put it back, available again after $delay seconds. With $refund the
     * attempt is given back (job middleware postponing a job, e.g. a rate
     * limit, is not a failed attempt).
     */
    public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void;

    /** @return array<string, array{pending: int, delayed: int, reserved: int, oldest: ?int}> per queue; oldest = created_at of the oldest available job */
    public function stats(): array;

    /** Delete every job in $queue (all queues when null). Returns how many. */
    public function clear(?string $queue = null): int;
}
