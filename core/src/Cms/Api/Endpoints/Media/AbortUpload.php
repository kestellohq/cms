<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Cancel an upload and drop what arrived. */
final class AbortUpload extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/media/uploads/{id}')->where('id', '[a-f0-9]{32}');
    }
    public function capability(): ?string
    {
        return 'media.upload';
    }
    public function scopes(): array
    {
        return ['media:write'];
    }
    public function summary(): string
    {
        return 'Cancel an upload';
    }

    public function handle(ApiRequest $request): mixed
    {
        self::library()->abort(self::upload($request));

        return null;
    }
}
