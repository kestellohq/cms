<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Delete a file. Files entries still use need ?force=1. */
final class DeleteMedia extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/media/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['media:write'];
    }
    public function summary(): string
    {
        return 'Delete a media file';
    }

    public function query(): ?Schema
    {
        return Schema::object(['force' => Schema::bool()->default(false)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $media = self::find($request);
        self::attempt(fn() => self::library()->delete($media, $request->principal, (bool) $request->query('force')));

        return null;
    }
}
