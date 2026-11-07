<?php

declare(strict_types=1);

namespace Kestello\Framework\Queue;

use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;
use Kestello\Framework\Database\Connection;

/**
 * The jobs table. Works on MySQL/MariaDB, PostgreSQL and SQLite with the
 * same SQL: a worker claims a row with a conditional UPDATE (only one
 * worker's UPDATE can match), so no dialect-specific locking is needed and
 * concurrent workers never run the same job. A crashed worker's job
 * becomes available again when its reservation expires.
 */
final class DatabaseQueue implements Queue
{
    private const string TABLE = 'jobs';

    public function __construct(private readonly Connection $db) {}

    public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string
    {
        // Stored as a hash: any length fits the column, and "Report" and "report" stay two keys under MySQL collations.
        $uniqueKey = $uniqueKey === null ? null : hash('sha256', $uniqueKey);
        $now = time();
        if ($uniqueKey !== null && $this->db->table(self::TABLE)->where('unique_key', $uniqueKey)->exists()) {
            return null;
        }

        try {
            return (string) $this->db->table(self::TABLE)->insert([
                'queue' => $queue,
                'payload' => $payload,
                'attempts' => 0,
                'unique_key' => $uniqueKey,
                'available_at' => $now + max(0, $delay),
                'created_at' => $now,
            ]);
        } catch (\PDOException $e) {
            if ($uniqueKey !== null && $this->db->table(self::TABLE)->where('unique_key', $uniqueKey)->exists()) {
                return null; // lost a race with an identical push
            }
            throw $e;
        }
    }

    public function reserve(array $queues, int $visibility): ?ReservedJob
    {
        $now = time();
        foreach ($queues as $queue) {
            // A few candidates, so contention with other workers rarely leaves us empty-handed.
            $candidates = $this->db->table(self::TABLE)
                ->where('queue', $queue)
                ->where('available_at', '<=', $now)
                ->where(static fn($q) => $q->whereNull('reserved_until')->orWhere('reserved_until', '<', $now))
                ->orderBy('available_at')->orderBy('id')
                ->limit(5)
                ->get();

            foreach ($candidates as $row) {
                $token = bin2hex(random_bytes(16));
                $claimed = $this->db->table(self::TABLE)
                    ->where('id', $row['id'])
                    ->where('attempts', $row['attempts'])
                    ->where('available_at', '<=', $now)     // released with a delay by another worker meanwhile
                    ->where(static fn($q) => $q->whereNull('reserved_until')->orWhere('reserved_until', '<', $now))
                    ->update([
                        'reservation' => $token,
                        'reserved_until' => $now + max(1, $visibility),
                        'attempts' => (int) $row['attempts'] + 1,
                    ]);
                if ($claimed === 1) {
                    return new ReservedJob((string) $row['id'], $queue, (string) $row['payload'], (int) $row['attempts'] + 1, $token);
                }
            }
        }

        return null;
    }

    public function touch(ReservedJob $job, int $visibility): void
    {
        $this->db->table(self::TABLE)->where('id', $job->id)->where('reservation', $job->reservation)
            ->update(['reserved_until' => time() + max(1, $visibility)]);
    }

    public function delete(ReservedJob $job): void
    {
        $this->db->table(self::TABLE)->where('id', $job->id)->where('reservation', $job->reservation)->delete();
    }

    public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void
    {
        $this->db->table(self::TABLE)->where('id', $job->id)->where('reservation', $job->reservation)->update([
            'reservation' => null,
            'reserved_until' => null,
            'available_at' => time() + max(0, $delay),
            ...($refund ? ['attempts' => max(0, $job->attempts - 1)] : []),
        ]);
    }

    public function stats(): array
    {
        $now = time();
        $out = [];
        foreach ($this->db->table(self::TABLE)->get() as $row) {
            // Queue tables stay small (done jobs are deleted), so counting in PHP keeps the SQL portable.
            $q = (string) $row['queue'];
            $out[$q] ??= ['pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest' => null];
            if ($row['reserved_until'] !== null && (int) $row['reserved_until'] >= $now) {
                $out[$q]['reserved']++;
            } elseif ((int) $row['available_at'] > $now) {
                $out[$q]['delayed']++;
            } else {
                $out[$q]['pending']++;
                $out[$q]['oldest'] = min($out[$q]['oldest'] ?? PHP_INT_MAX, (int) $row['created_at']);
            }
        }
        ksort($out);

        return $out;
    }

    public function clear(?string $queue = null): int
    {
        $query = $this->db->table(self::TABLE);
        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        return $query->delete();
    }
}
