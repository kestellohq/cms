<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Auth\AccountSecurity;

/** Sign out one of someone's sessions. */
final class RevokeUserSession extends SecurityEndpoint
{
    public function __construct(private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/security/sessions/{user}/{session}')->where('user', '\d{1,18}')->where('session', '[0-9a-f]{64}');
    }
    public function summary(): string
    {
        return 'Sign out one of someone\'s sessions';
    }

    public function handle(ApiRequest $request): mixed
    {
        if (!$this->accounts->revokeSession($request->intParam('user'), (string) $request->param('session'))) {
            throw ApiProblem::notFound('That person has no such session. It may have ended already.');
        }

        return null;
    }
}
