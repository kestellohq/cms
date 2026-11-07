<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Replace a menu's name and items (the whole tree is sent). */
final class UpdateMenu extends MenuEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/menus/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Save a menu';
    }

    public function body(): ?Schema
    {
        return Schema::object(['name' => Schema::string()->max(100), 'items' => Schema::any(262144, 16)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        self::menu($id);
        $items = $request->body['items'];
        self::attempt(fn() => self::menus()->save($id, (string) $request->body['name'], is_array($items) ? $items : [null]));
        self::audit($request, 'menu.updated', $id, ['name' => $request->body['name']]);

        return self::menu($id);
    }
}
