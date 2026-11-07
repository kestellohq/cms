<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Media\Media;

/** Change a file's title, alt text, caption or focal point (its uploader or media.manage). */
final class UpdateMedia extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/media/{id}')->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\MediaResource::class;
    }
    public function scopes(): array
    {
        return ['media:write'];
    }
    public function summary(): string
    {
        return 'Update a media file';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'title' => Schema::string()->max(191)->nullable()->optional(),
            'alt' => Schema::string()->max(1000)->nullable()->optional()->description('Describes the image for people who can\'t see it'),
            'caption' => Schema::string()->max(2000)->nullable()->optional(),
            'focus' => Schema::list(Schema::number()->min(0)->max(1))->min(2)->max(2)->optional()->description('Focal point [x, y] from the top left, 0–1; cropped sizes keep it in view'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $media = self::find($request);
        self::attempt(fn() => self::library()->update($media, $request->principal, $request->body));

        return self::present($request, [$media], usage: true)[0];
    }
}
