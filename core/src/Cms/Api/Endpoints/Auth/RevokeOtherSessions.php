<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountSecurity;

/** "Sign out everywhere else": every session except this one (a token keeps none). */
final class RevokeOtherSessions extends Endpoint
{
    public function __construct(private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/me/sessions');
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
        return 'Sign out all your other devices';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $keep = $request->principal->method === 'session' ? $request->principal->credentialId : null;

        return ['revoked' => $this->accounts->revokeOtherSessions((int) $request->userId(), $keep)];
    }
}
