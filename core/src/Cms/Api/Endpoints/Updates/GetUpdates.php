<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** The running core (and its releases on disk), plugins and themes with their versions. */
final class GetUpdates extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/updates');
    }
    public function summary(): string
    {
        return 'Versions of core, plugins and themes';
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->versions();
    }
}
