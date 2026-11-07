<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** One file, with the entries that use it. */
final class GetMedia extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/media/{id}')->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\MediaResource::class;
    }
    public function summary(): string
    {
        return 'Get a media file';
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::present($request, [self::find($request)], usage: true)[0];
    }
}
