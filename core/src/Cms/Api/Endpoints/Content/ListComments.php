<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Reviews;

/**
 * An entry's editorial timeline, oldest first: comments, and the review
 * steps between them (kind submitted, changes_requested, approved).
 */
final class ListComments extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries/{id}/comments')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return "List an entry's comments and review steps";
    }

    public function handle(ApiRequest $request): mixed
    {
        $entry = self::entry($request, self::type($request), withTrashed: true);

        return self::cms()->container->make(Reviews::class)->timeline($entry);
    }
}
