<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Reviews;

/** People entries of this type can be assigned to: those whose role may edit them. */
final class ListAssignees extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/assignees')->where('type', self::TYPE);
    }
    public function summary(): string
    {
        return 'List who entries of a type can be assigned to';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        if (!$request->principal->can($type->capability('edit'))) {
            throw ApiProblem::forbidden("You can't edit " . strtolower($type->plural()) . '.');
        }

        return self::cms()->container->make(Reviews::class)->assignable($type);
    }
}
