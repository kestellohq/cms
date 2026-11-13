<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\TwoFactor;

/** Replace your recovery codes (the old ones stop working). Shown once. */
final class RegenerateRecoveryCodes extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/me/2fa/recovery-codes');
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
        return [5, 3600];
    }
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Generate new recovery codes';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();
        if (!$this->twoFactor->isEnabled($userId)) {
            throw ApiProblem::conflict('Enable two-factor authentication first.');
        }

        return ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($userId)];
    }
}
