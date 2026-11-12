<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Entry;

/** Create an entry. Drafts may be incomplete; publishing checks required fields. */
final class CreateEntry extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries')->where('type', self::TYPE);
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Create an entry';
    }
    public function body(): ?Schema
    {
        return self::entryBody(create: true);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::attempt(fn() => self::entries()->create($type, $request->body, $request->principal));

        return self::present($request, $type, $entry);
    }
}
