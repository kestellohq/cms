<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Fleet\FleetSites;

/** The sites in the fleet, with the last report each gave. */
final class ListFleetSites extends FleetEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/fleet/sites');
    }
    public function summary(): string
    {
        return 'List the sites in the fleet';
    }
    public function collection(): bool
    {
        return false;
    }
    public function response(): ?Schema
    {
        return Schema::object(['sites' => Schema::list(self::siteSchema())]);
    }

    protected function act(ApiRequest $request, FleetSites $sites): mixed
    {
        return ['sites' => $sites->all()];
    }
}
