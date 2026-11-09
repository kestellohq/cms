<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Fleet\FleetSites;

/**
 * Add a site to the fleet with a token made on that site (only the "fleet" scope). The site is
 * asked for its report straight away; a failed check still adds it, with the error.
 */
final class AddFleetSite extends FleetEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/fleet/sites');
    }
    public function summary(): string
    {
        return 'Add a site to the fleet';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function body(): ?Schema
    {
        return Schema::object([
            'name' => Schema::string()->max(120),
            'url' => Schema::string()->max(300)->description('the home page, https only'),
            'token' => Schema::string()->max(500)->description('made on that site, with only the "fleet" scope'),
        ]);
    }
    public function response(): ?Schema
    {
        return self::siteSchema();
    }

    protected function act(ApiRequest $request, FleetSites $sites): mixed
    {
        $site = $sites->add((string) $request->input('name'), (string) $request->input('url'), (string) $request->input('token'));
        self::cms()->audit->record('fleet.site_added', ['fleet_site', $site['id']], ['name' => $site['name'], 'url' => $site['url']], $request->userId());

        return $site;
    }
}
