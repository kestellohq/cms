<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** "Test connection": build the proposed driver and run its health check, without saving. Blank secrets reuse the stored ones. */
final class TestPlatform extends PlatformEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/platform/{subsystem}/test')->where('subsystem', '[a-z][a-z0-9_-]{0,49}');
    }
    public function summary(): string
    {
        return 'Test driver settings';
    }

    public function handle(ApiRequest $request): mixed
    {
        return Response::json(['data' => $this->platform()->test($this->subsystem($request), (string) $request->input('driver'), (array) $request->input('options', []))]);
    }
}
