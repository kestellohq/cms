<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/** Forget one failed job ({id}) or all of them ("all"). */
final class DeleteFailedJobs extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/system/failed-jobs/{id}')->where('id', '[0-9]{1,18}|all');
    }
    public function summary(): string
    {
        return 'Delete failed jobs';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (string) $request->param('id');
        $q = QueryBuilder::table('failed_jobs');
        if ($id !== 'all') {
            $q = $q->where('id', (int) $id);
        }
        $count = $q->delete();
        if ($count === 0 && $id !== 'all') {
            throw ApiProblem::notFound('No such failed job.');
        }
        $this->cms()->audit->record('queue.forgotten', ['failed_jobs', $id], ['count' => $count], $request->userId());

        return Response::json(['data' => ['deleted' => $count]]);
    }
}
