<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Queue\Worker;
use Kestello\Cms\System\CoreTasks;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Queue\SyncQueue;

/** Queue sizes, the last worker heartbeat, the failed-job count and recent batches. */
final class GetQueue extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/queue');
    }
    public function summary(): string
    {
        return 'Queue status';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = $this->cms();
        $backend = $cms->queue->backend();
        $queues = [];
        foreach ($backend->stats() as $name => $s) {
            $queues[] = ['name' => (string) $name] + $s;
        }
        $beat = CoreTasks::cache($cms)->get(Worker::HEARTBEAT_KEY);

        return Response::json(['data' => [
            'sync' => $backend instanceof SyncQueue,
            'queues' => $queues,
            'worker' => is_array($beat) ? ['seen_at' => (int) $beat['at'], 'pid' => $beat['pid'] ?? null, 'host' => $beat['host'] ?? null] : null,
            'failed' => QueryBuilder::table('failed_jobs')->count(),
            'batches' => $cms->queue->batches()->recent(8),
            'jobs' => array_map(static fn(string $class, string $owner) => ['class' => $class, 'owner' => $owner], array_keys($cms->queue->registered()), array_values($cms->queue->registered())),
        ]]);
    }
}
