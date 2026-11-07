<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;

/** @internal */
abstract class ScheduledTaskEndpoint extends SystemEndpoint
{
    protected function taskName(ApiRequest $request): string
    {
        $name = rawurldecode((string) $request->param('name'));
        if ($this->cms()->schedule->get($name) === null) {
            throw ApiProblem::notFound('No such scheduled task.');
        }

        return $name;
    }
}
