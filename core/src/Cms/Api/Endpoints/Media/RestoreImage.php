<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Media\MediaEditor;

/** Undo an image's edits: the original is the file again (its uploader or media.manage). */
final class RestoreImage extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/media/{id}/restore')->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\MediaResource::class;
    }
    public function scopes(): array
    {
        return ['media:write'];
    }
    /** 409: There is no original to restore. */
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Restore an edited image\'s original';
    }

    public function handle(ApiRequest $request): mixed
    {
        $media = self::find($request);
        self::attempt(fn() => self::cms()->container->make(MediaEditor::class)->restore($media, $request->principal));

        return self::present($request, [$media], usage: true)[0];
    }
}
