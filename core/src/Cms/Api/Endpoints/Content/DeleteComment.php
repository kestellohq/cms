<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Reviews;

/** Delete a comment: your own, or anyone's when you may edit others' entries. Review steps cannot be deleted. */
final class DeleteComment extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/content/{type}/entries/{id}/comments/{comment}')->where('type', self::TYPE)->where('id', '\d{1,18}')->where('comment', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Delete a comment';
    }

    public function handle(ApiRequest $request): mixed
    {
        $entry = self::entry($request, self::type($request), withTrashed: true);
        self::attempt(fn() => self::cms()->container->make(Reviews::class)->deleteComment($entry, (int) $request->param('comment'), $request->principal));

        return null;
    }
}
