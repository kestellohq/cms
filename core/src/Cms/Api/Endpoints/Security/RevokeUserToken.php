<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Events\TokenRevoked;

/** Revoke anyone's access token: whatever uses it stops working at once. */
final class RevokeUserToken extends SecurityEndpoint
{
    public function __construct(private readonly PersonalAccessTokens $tokens) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/security/tokens/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Revoke someone\'s access token';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $token = $this->tokens->find($id) ?? throw ApiProblem::notFound('No such token. It may have been revoked already.');
        $this->tokens->revoke((int) $token['user_id'], $id);
        self::cms()->events->dispatch(new TokenRevoked((int) $token['user_id'], $id));

        return null;
    }
}
