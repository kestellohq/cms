<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Installed plugins with their status, declared permissions and dependencies. */
final class ListPlugins extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/plugins');
    }
    public function summary(): string
    {
        return 'List installed plugins';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->overview();
    }
}
