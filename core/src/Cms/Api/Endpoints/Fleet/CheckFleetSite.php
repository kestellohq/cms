<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Fleet\FleetSites;

/** Ask one site for its report now. */
final class CheckFleetSite extends FleetEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/fleet/sites/{id}/check')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Check a fleet site now';
    }
    public function response(): ?Schema
    {
        return self::siteSchema();
    }

    protected function act(ApiRequest $request, FleetSites $sites): mixed
    {
        return $sites->check($request->intParam('id'));
    }
}
