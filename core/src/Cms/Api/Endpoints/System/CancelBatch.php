<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** Stop a running batch: jobs that have not started are skipped; its finally job still runs. */
final class CancelBatch extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/queue/batches/{id}/cancel')->where('id', '[0-9a-f-]{1,36}');
    }
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Cancel a job batch';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = $this->cms();
        $id = (string) $request->param('id');
        $batch = $cms->queue->batches()->find($id) ?? throw ApiProblem::notFound('No such batch.');
        if (!$cms->queue->batches()->cancel($id)) {
            throw ApiProblem::conflict('This batch has already finished or been cancelled.');
        }
        $cms->audit->record('queue.batch_cancelled', ['job_batches', $id], ['name' => $batch['name']], $request->userId());

        return Response::json(['data' => $cms->queue->batches()->find($id)]);
    }
}
