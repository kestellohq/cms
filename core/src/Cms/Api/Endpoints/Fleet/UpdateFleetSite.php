<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Fleet\FleetSites;

/** Rename a site, or give it a new token (checked straight away). */
final class UpdateFleetSite extends FleetEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/fleet/sites/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Rename a fleet site or replace its token';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function body(): ?Schema
    {
        return Schema::object(['name' => Schema::string()->max(120)->optional(), 'token' => Schema::string()->max(500)->optional()]);
    }
    public function response(): ?Schema
    {
        return self::siteSchema();
    }

    protected function act(ApiRequest $request, FleetSites $sites): mixed
    {
        $input = array_filter(['name' => $request->input('name'), 'token' => $request->input('token')], 'is_string');
        $site = $sites->update($request->intParam('id'), $input);
        self::cms()->audit->record('fleet.site_updated', ['fleet_site', $site['id']], ['name' => $site['name'], 'token' => isset($input['token'])], $request->userId());

        return $site;
    }
}
