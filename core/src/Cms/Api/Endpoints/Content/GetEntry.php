<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Entry;

/** One entry with its field values and the caller's newer autosave, if any. */
final class GetEntry extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries/{id}')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
    }
    public function summary(): string
    {
        return 'Get an entry';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);

        return self::present($request, $type, self::entry($request, $type, withTrashed: true));
    }
}
