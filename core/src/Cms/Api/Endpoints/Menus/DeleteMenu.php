<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete a menu (its locations become empty). */
final class DeleteMenu extends MenuEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/menus/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Delete a menu';
    }

    public function status(): int
    {
        return 204;
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $menu = self::menu($id);
        self::menus()->delete($id);
        self::audit($request, 'menu.deleted', $id, ['name' => $menu['name']]);

        return null;
    }
}
