<?php

declare(strict_types=1);

namespace Kestello\Framework\Queue;

use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;

/** In memory, for tests. Same semantics as the database driver. */
final class ArrayQueue implements Queue
{
    /** @var array<int, array{queue: string, payload: string, attempts: int, unique: ?string, available: int, reservation: ?string, until: ?int, created: int}> */
    public array $jobs = [];
    private int $next = 1;

    public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string
    {
        if ($uniqueKey !== null && in_array($uniqueKey, array_column($this->jobs, 'unique'), true)) {
            return null;
        }
        $id = $this->next++;
        $this->jobs[$id] = ['queue' => $queue, 'payload' => $payload, 'attempts' => 0, 'unique' => $uniqueKey, 'available' => time() + max(0, $delay), 'reservation' => null, 'until' => null, 'created' => time()];

        return (string) $id;
    }

    public function reserve(array $queues, int $visibility): ?ReservedJob
    {
        $now = time();
        foreach ($queues as $queue) {
            foreach ($this->jobs as $id => &$job) {
                if ($job['queue'] === $queue && $job['available'] <= $now && ($job['until'] === null || $job['until'] < $now)) {
                    $job['attempts']++;
                    $job['reservation'] = bin2hex(random_bytes(16));
                    $job['until'] = $now + $visibility;
                    return new ReservedJob((string) $id, $queue, $job['payload'], $job['attempts'], $job['reservation']);
                }
            }
            unset($job);
        }

        return null;
    }

    public function touch(ReservedJob $job, int $visibility): void
    {
        if (($this->jobs[(int) $job->id]['reservation'] ?? null) === $job->reservation) {
            $this->jobs[(int) $job->id]['until'] = time() + $visibility;
        }
    }

    public function delete(ReservedJob $job): void
    {
        if (($this->jobs[(int) $job->id]['reservation'] ?? null) === $job->reservation) {
            unset($this->jobs[(int) $job->id]);
        }
    }

    public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void
    {
        if (($this->jobs[(int) $job->id]['reservation'] ?? null) === $job->reservation) {
            $attempts = $this->jobs[(int) $job->id]['attempts'] - ($refund ? 1 : 0);
            $this->jobs[(int) $job->id] = ['reservation' => null, 'until' => null, 'available' => time() + max(0, $delay), 'attempts' => max(0, $attempts)] + $this->jobs[(int) $job->id];
        }
    }

    public function stats(): array
    {
        $out = [];
        $now = time();
        foreach ($this->jobs as $job) {
            $s = &$out[$job['queue']];
            $s ??= ['pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest' => null];
            if ($job['until'] !== null && $job['until'] >= $now) {
                $s['reserved']++;
            } elseif ($job['available'] > $now) {
                $s['delayed']++;
            } else {
                $s['pending']++;
                $s['oldest'] = min($s['oldest'] ?? PHP_INT_MAX, $job['created']);
            }
            unset($s);
        }

        return $out;
    }

    public function clear(?string $queue = null): int
    {
        $before = count($this->jobs);
        $this->jobs = $queue === null ? [] : array_filter($this->jobs, static fn($j) => $j['queue'] !== $queue);

        return $before - count($this->jobs);
    }
}
