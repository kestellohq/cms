<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Create a content type or taxonomy. It appears from the next request on. */
final class CreateContentModel extends ContentModelEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content-models');
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
        return 'Create a content model';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'kind' => Schema::enum(['type', 'taxonomy']),
            'name' => Schema::string()->max(32),
            'definition' => Schema::any(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $b = $request->body;
        $model = self::attempt(fn() => self::models()->create((string) $b['kind'], (string) $b['name'], is_array($b['definition']) ? $b['definition'] : [], $request->userId()));
        self::audit($request, 'content_model.created', $model['id'], ['kind' => $model['kind'], 'name' => $model['name']]);

        return $model;
    }
}
