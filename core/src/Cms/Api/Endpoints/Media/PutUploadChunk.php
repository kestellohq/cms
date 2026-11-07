<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** One chunk of a chunked upload: the raw bytes as the request body (application/octet-stream). */
final class PutUploadChunk extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/media/uploads/{id}/chunks/{index}')->where('id', '[a-f0-9]{32}')->where('index', '\d{1,6}');
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
        return 'Send an upload chunk';
    }

    /** Its own bucket: a large file is many requests. */
    public function rateLimit(): ?array
    {
        return [600, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $row = self::upload($request);
        $bytes = $request->http->content((int) $row['chunk_size']) ?? throw ApiProblem::validation(['chunk' => ['The chunk is larger than the chunk size.']]);

        return self::attempt(fn() => self::library()->chunk($row, $request->intParam('index'), $bytes));
    }
}
