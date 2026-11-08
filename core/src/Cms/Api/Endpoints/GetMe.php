<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Framework\Database\QueryBuilder;

/** The authenticated user, with how they authenticated and what they may do. */
final class GetMe extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/me');
    }
    public function capability(): ?string
    {
        return 'read';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function resource(): ?string
    {
        return UserResource::class;
    }

    public function response(): ?\Kestello\Cms\Api\Schema
    {
        $S = \Kestello\Cms\Api\Schema::class;

        return $S::object(UserResource::properties())->extend([
            'pending_email' => $S::string()->nullable()->description('A new address waiting for its confirmation link to be opened; the account keeps email until then'),
            'auth' => $S::object([
                'method' => $S::string()->description('session, token or external'),
                'scopes' => $S::list($S::string())->nullable()->description('null for a browser session (no narrowing)'),
                'capabilities' => $S::list($S::string())->description('From the role; "*" means every capability'),
                'two_factor' => $S::object(['enabled' => $S::bool(), 'expected' => $S::bool()->description('Whether the role should use it')]),
            ]),
        ]);
    }
    public function summary(): string
    {
        return 'The current user';
    }

    public function handle(ApiRequest $request): mixed
    {
        $row = QueryBuilder::table('users')->select(...UserResource::COLUMNS)->where('id', $request->userId())->first()
            ?? throw ApiProblem::unauthenticated();

        $p = $request->principal;
        $data = (new UserResource($row))->resolve($p, \Kestello\Cms\Cms::instance()?->api);

        $cms = \Kestello\Cms\Cms::instance();
        $twoFactor = $cms?->container->has(\Kestello\Cms\Auth\TwoFactor::class)
            ? $cms->container->make(\Kestello\Cms\Auth\TwoFactor::class)->isEnabled((int) $request->userId())
            : false;

        $changes = $cms?->container->has(\Kestello\Cms\Auth\EmailChanges::class) ? $cms->container->make(\Kestello\Cms\Auth\EmailChanges::class) : null;

        return $data + [
            // a new address waiting for its link to be opened (the account keeps "email" until then)
            'pending_email' => $changes?->pending((int) $request->userId()),
            'auth' => [
                'method' => $p->method,
                'scopes' => $p->scopes,
                'capabilities' => $p->capabilities,
                'two_factor' => [
                    'enabled' => $twoFactor,
                    'expected' => in_array((string) $row['role'], \Kestello\Cms\Auth\AuthModule::twoFactorRoles($cms), true),
                ],
            ],
        ];
    }
}
