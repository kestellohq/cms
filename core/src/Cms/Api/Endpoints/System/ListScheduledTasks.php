<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Cms\System\CoreTasks;
use Kestello\Framework\Core\Response;

/** Scheduled tasks with their next and last runs, and the scheduler heartbeat. */
final class ListScheduledTasks extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/schedule');
    }
    public function summary(): string
    {
        return 'List scheduled tasks';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = $this->cms();
        $now = time();
        $rows = $this->runner()->sync($now);
        $data = [];
        foreach ($cms->schedule->tasks() as $name => $task) {
            $row = $rows[$name] ?? [];
            $data[] = [
                'name' => $name,
                'description' => $task->getDescription(),
                'type' => $task->type,
                'owner' => $task->owner,
                'cron' => $task->expression(),
                'timezone' => $task->zone()->getName(),
                'disabled' => (bool) ($row['disabled'] ?? false),
                'next_run_at' => (bool) ($row['disabled'] ?? false) ? null : $task->nextDue($now),
                'last' => isset($row['last_started_at']) ? [
                    'started_at' => (int) $row['last_started_at'],
                    'finished_at' => $row['last_finished_at'] !== null ? (int) $row['last_finished_at'] : null,
                    'duration_ms' => $row['last_duration_ms'] !== null ? (int) $row['last_duration_ms'] : null,
                    'status' => $row['last_status'],
                    'output' => $row['last_output'],
                ] : null,
            ];
        }
        $beat = CoreTasks::cache($cms)->get(ScheduleRunner::HEARTBEAT_KEY);

        return Response::json(['data' => $data, 'meta' => ['heartbeat' => is_array($beat) ? ['at' => (int) $beat['at'], 'via' => (string) ($beat['via'] ?? '')] : null]]);
    }
}
