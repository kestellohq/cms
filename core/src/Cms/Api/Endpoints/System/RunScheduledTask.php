<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/** "Run now": runs the task in this request, whether due or not. */
final class RunScheduledTask extends ScheduledTaskEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/schedule/{name}/run')->where('name', '[^/]{1,300}');
    }
    public function summary(): string
    {
        return 'Run a scheduled task now';
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = $this->taskName($request);
        $status = $this->runner()->runNow($name);
        $row = QueryBuilder::table('scheduled_tasks')->where('name', $name)->first() ?? [];
        $this->cms()->audit->record('schedule.run', ['scheduled_task', $name], ['status' => $status], $request->userId());

        return Response::json(['data' => ['status' => $status, 'duration_ms' => isset($row['last_duration_ms']) ? (int) $row['last_duration_ms'] : null, 'output' => $row['last_output'] ?? null]]);
    }
}
