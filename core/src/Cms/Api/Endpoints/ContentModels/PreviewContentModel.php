<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** What saving a definition would change, with the number of entries each change touches. Saves nothing. */
final class PreviewContentModel extends ContentModelEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content-models/{id}/preview')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Preview content model changes';
    }

    public function body(): ?Schema
    {
        return Schema::object(['definition' => Schema::any()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->param('id');
        $def = $request->body['definition'];

        return ['changes' => self::attempt(fn() => self::models()->preview($id, is_array($def) ? $def : []))];
    }
}
