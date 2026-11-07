<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** Infrastructure subsystems (cache, sessions, queue…), their drivers and settings. Secret values are never returned. */
final class ListPlatform extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/platform');
    }
    public function summary(): string
    {
        return 'List infrastructure drivers and settings';
    }

    public function handle(ApiRequest $request): mixed
    {
        return Response::json(['data' => $this->platform()->describeAll()]);
    }
}
