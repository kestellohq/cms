<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Pause or resume a task. */
final class UpdateScheduledTask extends ScheduledTaskEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/system/schedule/{name}')->where('name', '[^/]{1,300}');
    }
    public function summary(): string
    {
        return 'Enable or disable a scheduled task';
    }

    public function body(): ?Schema
    {
        return Schema::object(['disabled' => Schema::bool()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = $this->taskName($request);
        $disabled = (bool) $request->input('disabled');
        $this->runner()->setDisabled($name, $disabled);
        $this->cms()->audit->record($disabled ? 'schedule.disabled' : 'schedule.enabled', ['scheduled_task', $name], [], $request->userId());

        return null;
    }
}
