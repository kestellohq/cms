<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Framework\Database\QueryBuilder;

final class GetUser extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/users/{id}')->where('id', '\d+');
    }
    public function capability(): ?string
    {
        return 'users.read';
    }
    public function scopes(): array
    {
        return ['users:read'];
    }
    public function resource(): ?string
    {
        return UserResource::class;
    }
    public function summary(): string
    {
        return 'Get a user';
    }

    public function handle(ApiRequest $request): mixed
    {
        $row = QueryBuilder::table('users')->select(...UserResource::COLUMNS)->where('id', $request->intParam('id'))->first();

        return $row === null ? throw ApiProblem::notFound('No such user.') : new UserResource($row);
    }
}
