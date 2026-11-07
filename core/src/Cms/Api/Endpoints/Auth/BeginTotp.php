<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Auth\TwoFactor;
use Kestello\Cms\Cms;

/**
 * Start enrolling an authenticator app: returns the secret and an
 * otpauth:// URI to show as a QR code. Finish with /me/2fa/totp/confirm.
 */
final class BeginTotp extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/me/2fa/totp');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 3600];
    }
    public function summary(): string
    {
        return 'Start authenticator-app enrollment';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        [$site] = AuthModule::site(Cms::instance());
        $account = (string) ($request->principal->user['email'] ?: $request->principal->user['username']);

        try {
            return $this->twoFactor->beginTotp((int) $request->userId(), $account, $site);
        } catch (\LogicException $e) {
            throw ApiProblem::conflict($e->getMessage());
        }
    }
}
