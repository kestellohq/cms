<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Every menu (items unresolved) and the active theme's locations with their menus. */
final class ListMenus extends MenuEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/menus');
    }
    public function summary(): string
    {
        return 'List menus and menu locations';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        $assigned = self::menus()->assignments();
        $locations = [];
        foreach (self::cms()->menus->locations() as $name => $loc) {
            $locations[] = ['name' => $name, 'label' => $loc['label'], 'description' => $loc['description'], 'owner' => $loc['owner'], 'menu_id' => $assigned[$name] ?? null];
        }

        return [
            'menus' => array_map(static fn(array $m) => ['id' => $m['id'], 'name' => $m['name'], 'items' => count($m['items']), 'locations' => $m['locations'], 'updated_at' => $m['updated_at']], self::menus()->all()),
            'locations' => $locations,
        ];
    }
}
