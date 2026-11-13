<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Queue\SyncQueue;

/** Put one failed job ({id}) or all of them ("all") back on their queue. */
final class RetryFailedJobs extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/failed-jobs/{id}/retry')->where('id', '[0-9]{1,18}|all');
    }
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Retry failed jobs';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = $this->cms();
        $backend = $cms->queue->backend();
        if ($backend instanceof SyncQueue) {
            throw ApiProblem::conflict('The queue driver is "sync"; switch to a real queue to retry jobs.');
        }
        $id = (string) $request->param('id');
        $q = QueryBuilder::table('failed_jobs');
        if ($id !== 'all') {
            $q = $q->where('id', (int) $id);
        }
        $count = 0;
        foreach ($q->limit(1000)->get() as $row) {
            $backend->push((string) $row['queue'], self::detachFromBatch((string) $row['payload']));
            QueryBuilder::table('failed_jobs')->where('id', $row['id'])->delete();
            $count++;
        }
        if ($count === 0 && $id !== 'all') {
            throw ApiProblem::notFound('No such failed job.');
        }
        $cms->audit->record('queue.retried', ['failed_jobs', $id], ['count' => $count], $request->userId());

        return Response::json(['data' => ['retried' => $count]]);
    }

    /** A retried batch job runs on its own: its batch already counted the failure and may be finished. */
    public static function detachFromBatch(string $payload): string
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || !array_key_exists('batch', $data)) {
            return $payload;
        }
        unset($data['batch']);

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
