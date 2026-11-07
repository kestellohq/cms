<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\PersonalAccessTokens;

/** Revoke one of your tokens. Someone else's token answers 404, not 403. */
final class RevokeMyToken extends Endpoint
{
    public function __construct(private readonly PersonalAccessTokens $tokens) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/me/tokens/{id}')->where('id', '\d+');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function summary(): string
    {
        return 'Revoke a personal access token';
    }

    public function handle(ApiRequest $request): mixed
    {
        if (!$this->tokens->revoke((int) $request->userId(), $request->intParam('id'))) {
            throw ApiProblem::notFound('No such token.');
        }
        \Kestello\Cms\Cms::instance()?->events->dispatch(new \Kestello\Cms\Events\TokenRevoked((int) $request->userId(), $request->intParam('id')));

        return null;
    }
}
