<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Put menus in theme locations: {"locations": {"primary": 3, "footer": null}}.
 * Locations left out keep their menu. With "locale": "es" the choices are
 * Spanish's own, and null there means it uses the main language's menu.
 */
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
        return Schema::object([
            'locations' => Schema::dictionary(50),
            'locale' => Schema::string()->max(12)->optional()->description('The language these choices are for: main (or empty, the default) or a code such as es'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $locale = self::locale($request->body['locale'] ?? null);
        foreach ((array) $request->body['locations'] as $location => $menuId) {
            self::attempt(fn() => self::menus()->assign((string) $location, $menuId === null || $menuId === '' ? null : (int) $menuId, $locale));
        }
        self::cms()->audit->record('menu.locations', null, ['locations' => $request->body['locations'], 'locale' => $locale], $request->userId());

        return self::menus()->assignments($locale);
    }
}
