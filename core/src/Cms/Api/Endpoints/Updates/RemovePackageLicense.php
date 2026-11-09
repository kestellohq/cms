<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Forget the license key of a plugin or theme. */
final class RemovePackageLicense extends PackageUpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/packages/license/remove');
    }

    public function summary(): string
    {
        return 'Remove a plugin or theme license key';
    }

    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function body(): ?Schema
    {
        return Schema::object(['owner' => self::ownerSchema()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $owner = (string) $request->input('owner');
        $this->licenses()->remove($owner);
        $this->cms()->audit->record('package.license_removed', explode(':', $owner, 2), [], $request->userId());

        return $this->versions();
    }
}
