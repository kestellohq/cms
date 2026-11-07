<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Previews;

/** Revoke a share link: it stops working at once. */
final class DeletePreview extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/content/{type}/entries/{id}/previews/{preview}')->where('type', self::TYPE)->where('id', '\d{1,18}')->where('preview', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Revoke a preview link';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $id = (int) $request->param('preview');
        if (!self::attempt(fn() => self::cms()->container->make(Previews::class)->revoke($entry, $type, $request->principal, $id))) {
            throw ApiProblem::notFound('No such preview link.');
        }
        self::cms()->audit->record('content.preview_revoked', ['entry', (int) $entry->getKey()], ['link' => $id], $request->userId());

        return null;
    }
}
