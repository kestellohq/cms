<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** One menu, each item with its target's current title, address and whether it shows on the site. */
final class GetMenu extends MenuEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/menus/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Get a menu';
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::menu($request->intParam('id'));
    }
}
