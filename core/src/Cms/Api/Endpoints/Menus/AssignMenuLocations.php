<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Put menus in theme locations: {"locations": {"primary": 3, "footer": null}}. Locations left out keep their menu. */
final class AssignMenuLocations extends MenuEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/menus/locations');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Assign menus to locations';
    }

    public function body(): ?Schema
    {
        return Schema::object(['locations' => Schema::dictionary(50)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        foreach ((array) $request->body['locations'] as $location => $menuId) {
            self::attempt(fn() => self::menus()->assign((string) $location, $menuId === null || $menuId === '' ? null : (int) $menuId));
        }
        self::cms()->audit->record('menu.locations', null, ['locations' => $request->body['locations']], $request->userId());

        return self::menus()->assignments();
    }
}
