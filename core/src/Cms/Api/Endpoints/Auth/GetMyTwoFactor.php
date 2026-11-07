<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\TwoFactor;

/** Whether 2FA is on, and how many recovery codes are left. */
final class GetMyTwoFactor extends Endpoint
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/2fa');
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
        return 'Your two-factor status';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->twoFactor->status((int) $request->userId());
    }
}
