<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue;

use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Json;

/**
 * The job_batches table. Counters change with single-row UPDATEs and the
 * "who pushes the callbacks" decisions are conditional UPDATEs, so with any
 * number of workers each callback is pushed exactly once.
 *
 * @internal used by JobQueue; read through $cms->queue->batches()
 */
final class Batches
{
    private const string TABLE = 'job_batches';

    /** @param array<string, mixed> $options */
    public function create(string $id, string $name, string $owner, int $total, array $options): void
    {
        QueryBuilder::table(self::TABLE)->insert([
            'id' => $id,
            'name' => mb_substr($name, 0, 191),
            'owner' => mb_substr($owner, 0, 100),
            'total' => $total,
            'pending' => $total,
            'failed' => 0,
            'options' => Json::encode($options),
            'created_at' => time(),
        ]);
    }

    /** @return array<string, mixed>|null public view (no callback payloads) */
    public function find(string $id): ?array
    {
        $row = QueryBuilder::table(self::TABLE)->where('id', $id)->first();

        return $row === null ? null : self::present($row);
    }

    /** @return list<array<string, mixed>> running batches first, then the newest finished ones */
    public function recent(int $limit = 10): array
    {
        $running = QueryBuilder::table(self::TABLE)->whereNull('finished_at')->orderByDesc('created_at')->limit($limit)->get();
        $done = QueryBuilder::table(self::TABLE)->whereNotNull('finished_at')->orderByDesc('finished_at')->limit(max(0, $limit - count($running)))->get();

        return array_map(self::present(...), [...$running, ...$done]);
    }

    public function isCancelled(string $id): bool
    {
        return QueryBuilder::table(self::TABLE)->where('id', $id)->whereNotNull('cancelled_at')->exists();
    }

    /** Stop the batch: jobs not yet started are skipped. False when already cancelled or finished. */
    public function cancel(string $id): bool
    {
        return QueryBuilder::table(self::TABLE)->where('id', $id)->whereNull('cancelled_at')->whereNull('finished_at')->update(['cancelled_at' => time()]) === 1;
    }

    /**
     * A job of the batch is settled (done, failed for good, or skipped).
     * Returns the callback job descriptors to push now.
     *
     * @return list<array{job: string, args: array<string, mixed>}>
     */
    public function settle(string $id, bool $failed, ?string $job = null): array
    {
        $row = QueryBuilder::table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return [];
        }
        // each job counts once, however many times it ran
        if ($job !== null && QueryBuilder::table('job_batch_jobs')->upsert([['batch_id' => $id, 'job_uuid' => $job]], ['batch_id', 'job_uuid'], []) !== 1) {
            return [];
        }
        $options = Json::tryDecode((string) $row['options']);
        $options = is_array($options) ? $options : [];
        $push = [];

        // Count the failure before the job leaves "pending", so whoever sees pending = 0 sees it.
        if ($failed) {
            QueryBuilder::table(self::TABLE)->where('id', $id)->increment('failed');
            $first = QueryBuilder::table(self::TABLE)->where('id', $id)->whereNull('caught_at')->update(['caught_at' => time()]) === 1;
            if ($first && isset($options['catch'])) {
                $push[] = $options['catch'];
            }
            if (empty($options['allow_failures'])) {
                $this->cancel($id);
            }
        }
        QueryBuilder::table(self::TABLE)->where('id', $id)->decrement('pending');

        $won = QueryBuilder::table(self::TABLE)->where('id', $id)->where('pending', '<=', 0)->whereNull('finished_at')->update(['finished_at' => time()]) === 1;
        if ($won) {
            $failures = (int) QueryBuilder::table(self::TABLE)->where('id', $id)->value('failed');
            if (isset($options['then']) && ($failures === 0 || !empty($options['allow_failures']))) {
                $push[] = $options['then'];
            }
            if (isset($options['finally'])) {
                $push[] = $options['finally'];
            }
        }

        return $push;
    }

    /** Remove finished batches older than $seconds. */
    public function prune(int $seconds): int
    {
        $old = QueryBuilder::table(self::TABLE)->whereNotNull('finished_at')->where('finished_at', '<', time() - $seconds)->pluck('id');
        if ($old === []) {
            return 0;
        }
        foreach (array_chunk($old, 500) as $ids) {
            QueryBuilder::table('job_batch_jobs')->whereIn('batch_id', $ids)->delete();
        }

        return QueryBuilder::table(self::TABLE)->whereIn('id', $old)->delete();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $total = (int) $row['total'];
        $pending = max(0, (int) $row['pending']);
        $options = Json::tryDecode((string) $row['options']);

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'owner' => (string) $row['owner'],
            'total' => $total,
            'pending' => $pending,
            'processed' => $total - $pending,
            'failed' => (int) $row['failed'],
            'progress' => $total === 0 ? 100 : (int) floor(($total - $pending) * 100 / $total),
            'allow_failures' => is_array($options) && !empty($options['allow_failures']),
            'created_at' => (int) $row['created_at'],
            'cancelled_at' => $row['cancelled_at'] !== null ? (int) $row['cancelled_at'] : null,
            'finished_at' => $row['finished_at'] !== null ? (int) $row['finished_at'] : null,
            'status' => match (true) {
                $row['finished_at'] !== null && $row['cancelled_at'] !== null => 'cancelled',
                $row['finished_at'] !== null => (int) $row['failed'] > 0 ? 'finished_with_failures' : 'finished',
                $row['cancelled_at'] !== null => 'cancelling',
                default => 'running',
            },
        ];
    }
}
