<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountSecurity;

/** Sign out one device. Someone else's session answers 404. */
final class RevokeMySession extends Endpoint
{
    public function __construct(private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/me/sessions/{id}')->where('id', '[0-9a-f]{64}');
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
        return 'Sign out one of your devices';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        if (!$this->accounts->revokeSession((int) $request->userId(), (string) $request->param('id'))) {
            throw ApiProblem::notFound('No such session.');
        }

        return null;
    }
}
