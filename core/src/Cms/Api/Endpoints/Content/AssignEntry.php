<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Reviews;

/** Assign an entry to someone who can edit entries of its type, or to nobody (user_id null). */
final class AssignEntry extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/content/{type}/entries/{id}/assignee')->where('type', self::TYPE)->where('id', '\d{1,18}');
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
        return 'Assign an entry';
    }
    public function body(): ?Schema
    {
        return Schema::object(['user_id' => Schema::int()->min(1)->nullable()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $user = $request->input('user_id');
        $entry = self::attempt(fn() => self::cms()->container->make(Reviews::class)->assign($entry, $user === null ? null : (int) $user, $request->principal));

        return self::present($request, $type, $entry);
    }
}
