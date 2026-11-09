<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Fleet\FleetSites;

/** Take a site out of the fleet. Its token stays valid on that site until revoked there. */
final class DeleteFleetSite extends FleetEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/fleet/sites/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Remove a site from the fleet';
    }

    protected function act(ApiRequest $request, FleetSites $sites): mixed
    {
        $site = $sites->find($request->intParam('id'));
        $sites->remove($site['id']);
        self::cms()->audit->record('fleet.site_removed', ['fleet_site', $site['id']], ['name' => $site['name'], 'url' => $site['url']], $request->userId());

        return null;
    }
}
