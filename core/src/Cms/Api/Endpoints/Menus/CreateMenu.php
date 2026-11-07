<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Create a menu. */
final class CreateMenu extends MenuEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/menus');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Create a menu';
    }

    public function body(): ?Schema
    {
        return Schema::object(['name' => Schema::string()->max(100), 'items' => Schema::any(262144, 16)->optional()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $items = $request->body['items'] ?? [];
        $id = self::attempt(fn() => self::menus()->save(null, (string) $request->body['name'], is_array($items) ? $items : [null]));
        self::audit($request, 'menu.created', $id, ['name' => $request->body['name']]);

        return self::menu($id);
    }
}
