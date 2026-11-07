<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Reviews;

/** Comment on an entry. Plain text; only people in the admin see it. */
final class CreateComment extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries/{id}/comments')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Comment on an entry';
    }
    public function body(): ?Schema
    {
        return Schema::object(['body' => Schema::string()->max(Reviews::MAX_BODY)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $entry = self::entry($request, self::type($request));
        $reviews = self::cms()->container->make(Reviews::class);
        $id = self::attempt(fn() => $reviews->comment($entry, (string) $request->input('body'), $request->principal));

        return array_values(array_filter($reviews->timeline($entry), static fn(array $c) => $c['id'] === $id))[0] ?? ['id' => $id];
    }
}
