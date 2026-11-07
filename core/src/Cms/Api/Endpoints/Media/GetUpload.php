<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** An upload in progress: how many chunks arrived (to resume). */
final class GetUpload extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/media/uploads/{id}')->where('id', '[a-f0-9]{32}');
    }
    public function capability(): ?string
    {
        return 'media.upload';
    }
    public function summary(): string
    {
        return 'Get an upload in progress';
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::library()->describeUpload(self::upload($request));
    }
}
