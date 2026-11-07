<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Kernel;

/** Health check (uptime monitors, the updater). The version is only shown to admins. */
final class GetStatus extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/status');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function summary(): string
    {
        return 'Health check';
    }

    public function handle(ApiRequest $request): mixed
    {
        return array_filter([
            'status' => 'ok',
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => ($request->principal->can('core.update') || $request->principal->can('plugins.manage')) ? Kernel::version() : null,
        ], static fn($v) => $v !== null);
    }
}
