<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** One built model, and its definition as PHP for moving it into a plugin or theme. */
final class GetContentModel extends ContentModelEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content-models/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Get a content model';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->param('id');

        return self::attempt(fn() => self::models()->find($id) + ['php' => self::models()->export($id)]);
    }
}
