<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Check the uploaded file and add it to the library. */
final class CompleteUpload extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/media/uploads/{id}/complete')->where('id', '[a-f0-9]{32}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\MediaResource::class;
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
        return 'Finish an upload';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'title' => Schema::string()->max(191)->nullable()->optional(),
            'alt' => Schema::string()->max(1000)->nullable()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $row = self::upload($request);
        $media = self::attempt(fn() => self::library()->complete($row, $request->principal, $request->body));

        return self::present($request, [$media], usage: true)[0];
    }
}
