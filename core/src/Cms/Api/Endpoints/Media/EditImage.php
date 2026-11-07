<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Media\MediaEditor;

/**
 * Crop, turn or flip an image (its uploader or media.manage). Applied to
 * the original in this order: turn, flip, crop. The result is a new file at
 * a new address; the original turns private until the image is restored.
 * Sending no change restores the original.
 */
final class EditImage extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/media/{id}/edit')->where('id', '\d{1,18}');
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
        return 'Crop, turn or flip an image';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'rotate' => Schema::int()->min(-270)->max(360)->optional()->description('Clockwise, in quarter turns: 0, 90, 180 or 270'),
            'flip_horizontal' => Schema::bool()->optional()->description('Mirror left to right'),
            'flip_vertical' => Schema::bool()->optional()->description('Mirror top to bottom'),
            'crop' => Schema::object([
                'x' => Schema::number()->min(0)->max(1), 'y' => Schema::number()->min(0)->max(1),
                'width' => Schema::number()->min(0)->max(1), 'height' => Schema::number()->min(0)->max(1),
            ])->nullable()->optional()->description('Fractions of the turned and flipped picture, from the top left'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $media = self::find($request);
        self::attempt(fn() => self::cms()->container->make(MediaEditor::class)->edit($media, $request->principal, $request->body));

        return self::present($request, [$media], usage: true)[0];
    }
}
