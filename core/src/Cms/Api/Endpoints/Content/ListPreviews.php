<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Previews;

/** The entry's share links that have not expired. The links themselves are only shown once, when made. */
final class ListPreviews extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries/{id}/previews')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'List share links of an entry';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        if (!self::entries()->can($request->principal, $type, 'edit', $entry)) {
            throw ApiProblem::forbidden("You can't change this " . strtolower($type->singular()) . '.');
        }

        return self::cms()->container->make(Previews::class)->shareLinks($entry);
    }
}
