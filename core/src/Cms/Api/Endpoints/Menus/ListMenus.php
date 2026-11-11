<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Every menu (items unresolved) and the active theme's locations with
 * their menus in one language. In a language other than the main one,
 * menu_id is that language's own choice (null: it uses main_menu_id).
 */
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

    public function query(): ?Schema
    {
        return Schema::object([
            'locale' => Schema::string()->max(12)->default('main')->description('The language whose locations to show: main (or empty) for the main one, or a code such as es'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $locale = self::locale($request->query('locale'));
        $assigned = self::menus()->assignments($locale);
        $main = $locale === '' ? $assigned : self::menus()->assignments();
        $locations = [];
        foreach (self::cms()->menus->locations() as $name => $loc) {
            $locations[] = ['name' => $name, 'label' => $loc['label'], 'description' => $loc['description'], 'owner' => $loc['owner'], 'menu_id' => $assigned[$name] ?? null]
                + ($locale === '' ? [] : ['main_menu_id' => $main[$name] ?? null]);
        }

        return [
            'menus' => array_map(static fn(array $m) => ['id' => $m['id'], 'name' => $m['name'], 'items' => count($m['items']), 'locations' => $m['locations'], 'placements' => $m['placements'], 'updated_at' => $m['updated_at']], self::menus()->all()),
            'locations' => $locations,
            'locale' => $locale,
        ];
    }
}
