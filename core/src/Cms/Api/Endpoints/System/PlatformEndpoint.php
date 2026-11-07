<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Schema;

/** @internal */
abstract class PlatformEndpoint extends SystemEndpoint
{
    public function body(): ?Schema
    {
        return Schema::object(['driver' => Schema::string()->max(100), 'options' => Schema::dictionary()->default([])]);
    }

    protected function subsystem(ApiRequest $request): string
    {
        $name = (string) $request->param('subsystem');
        if (!$this->platform()->registry()->hasSubsystem($name)) {
            throw ApiProblem::notFound('No such subsystem.');
        }

        return $name;
    }
}
