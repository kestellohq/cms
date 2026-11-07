<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Framework\Core\Response;

/** Sign someone out everywhere. Your own session stays signed in. */
final class RevokeUserSessions extends SecurityEndpoint
{
    public function __construct(private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/security/sessions/{user}')->where('user', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Sign someone out everywhere';
    }

    public function response(): ?Schema
    {
        return Schema::object(['signed_out' => Schema::int()->description('How many sessions ended')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $user = $request->intParam('user');
        $keep = $user === $request->userId() ? self::currentSession($request) : null;

        return Response::json(['data' => ['signed_out' => $this->accounts->revokeOtherSessions($user, $keep)]]);
    }
}
