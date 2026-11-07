<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** Every form with its submission counts. */
final class ListForms extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/forms');
    }
    public function summary(): string
    {
        return 'List forms';
    }

    public function handle(ApiRequest $request): mixed
    {
        return Response::json(['data' => self::forms()->all()]);
    }
}
