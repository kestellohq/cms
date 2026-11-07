<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete a content type or taxonomy nothing uses any more (409 while entries or terms exist). */
final class DeleteContentModel extends ContentModelEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/content-models/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }   // changes the site's structure
    public function summary(): string
    {
        return 'Delete a content model';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->param('id');
        $model = self::attempt(fn() => self::models()->find($id));
        self::attempt(fn() => self::models()->delete($id));
        self::audit($request, 'content_model.deleted', $id, ['kind' => $model['kind'], 'name' => $model['name']]);

        return null;
    }
}
