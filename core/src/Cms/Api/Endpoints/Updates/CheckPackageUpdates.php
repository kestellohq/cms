<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Ask the update sources of every plugin and theme (or one) now; otherwise the schedule does. Installs nothing. */
final class CheckPackageUpdates extends PackageUpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/packages/check');
    }

    public function summary(): string
    {
        return 'Check for new plugin and theme versions';
    }

    public function rateLimit(): ?array
    {
        return [6, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object(['owner' => self::ownerSchema()->optional()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $owner = $request->input('owner');
        if (is_string($owner)) {
            $this->source($owner);
        }
        $this->updates()->check(only: is_string($owner) ? $owner : null);

        return $this->versions();
    }
}
