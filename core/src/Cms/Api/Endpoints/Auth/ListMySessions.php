<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Framework\Core\Response;

/** Your signed-in devices. "current" marks this browser. */
final class ListMySessions extends Endpoint
{
    public function __construct(private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/sessions');
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
        return 'List your signed-in devices';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $current = $request->principal->method === 'session' ? $request->principal->credentialId : null;

        return Response::json([
            'data' => $this->accounts->sessions((int) $request->userId(), $current),
            'meta' => ['supported' => $this->accounts->tracksDevices()],
        ]);
    }
}
