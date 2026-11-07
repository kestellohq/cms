<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Ask GitHub now whether a new Kestello version is out (otherwise the schedule does). Installs nothing. */
final class CheckForUpdates extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/core/check');
    }
    public function summary(): string
    {
        return 'Check for a new Kestello version';
    }
    public function rateLimit(): ?array
    {
        return [6, 60]; // GitHub allows a server 60 unauthenticated requests an hour
    }

    public function handle(ApiRequest $request): mixed
    {
        $this->checker()->check();

        return $this->versions();
    }
}
