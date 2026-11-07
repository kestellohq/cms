<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** What uploads may be: size, types, whether they go straight to storage. */
final class GetMediaLimits extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/media/limits');
    }
    public function summary(): string
    {
        return 'Upload limits';
    }

    public function handle(ApiRequest $request): mixed
    {
        $lib = self::library();
        $types = $lib->types();

        return [
            'max_bytes' => $lib->maxBytes(),
            'max_megapixels' => intdiv($lib->maxPixels(), 1_000_000),
            'kinds' => $types->enabledKinds(),
            'accept' => $types->accept(),
            'accept_by_kind' => array_combine($types->enabledKinds(), array_map($types->accept(...), $types->enabledKinds())),
            'chunk_size' => \Kestello\Cms\Media\MediaLibrary::CHUNK_SIZE,
            'can_upload' => $request->principal->can('media.upload'),
            'quota' => self::quota($request),
        ];
    }

    /** @return array{limit_bytes: int, used_bytes: int}|null null: no limit */
    private static function quota(ApiRequest $request): ?array
    {
        $storage = \Kestello\Cms\Cms::instance()?->container->make(\Kestello\Cms\Media\MediaStorage::class);
        $mb = $storage?->quotaFor($request->principal);
        if ($mb === null || $request->principal->userId === null) {
            return null;
        }

        return ['limit_bytes' => $mb * 1_048_576, 'used_bytes' => $storage->usedBy($request->principal->userId)];
    }
}
