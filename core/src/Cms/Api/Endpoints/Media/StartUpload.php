<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Media\Media;

/**
 * Begin an upload. Mode "chunked": PUT the bytes to /media/uploads/{id}/chunks/{n}.
 * Mode "direct": POST them with direct.fields to direct.url (the bucket).
 * Then POST /media/uploads/{id}/complete.
 */
final class StartUpload extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/media/uploads');
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
        return 'Start an upload';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'filename' => Schema::string()->min(1)->max(255),
            'size' => Schema::int()->min(0)->max(PHP_INT_MAX),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::attempt(fn() => self::library()->start($request->principal, (string) $request->body['filename'], (int) $request->body['size']));
    }
}
