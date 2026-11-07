<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/** Failed jobs, newest first. Arguments that look secret are masked; the error keeps its first lines only. */
final class ListFailedJobs extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/failed-jobs');
    }
    public function summary(): string
    {
        return 'List failed jobs';
    }

    public function query(): ?Schema
    {
        return Schema::object(['limit' => Schema::int()->min(1)->max(100)->default(25), 'before' => Schema::int()->min(1)->optional()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $limit = (int) $request->query('limit', 25);
        $q = QueryBuilder::table('failed_jobs');
        if (is_int($before = $request->query('before'))) {
            $q = $q->where('id', '<', $before);
        }
        $rows = $q->orderBy('id', 'desc')->limit($limit + 1)->get();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return Response::json([
            'data' => array_map(static fn(array $r) => [
                'id' => (int) $r['id'],
                'uuid' => (string) $r['uuid'],
                'queue' => (string) $r['queue'],
                'job' => (string) $r['job'],
                'owner' => (string) $r['owner'],
                'attempts' => (int) $r['attempts'],
                'failed_at' => (int) $r['failed_at'],
                'args' => JobQueue::displayPayload((string) $r['payload'])['args'] ?? null,
                'error' => implode("\n", array_slice(explode("\n", (string) $r['exception']), 0, 12)),
            ], $rows),
            'meta' => ['next_before' => $more && $rows !== [] ? (int) end($rows)['id'] : null, 'total' => QueryBuilder::table('failed_jobs')->count()],
        ]);
    }
}
