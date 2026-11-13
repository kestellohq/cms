<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\TwoFactor;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\TwoFactorDisabled;

/** Turn two-factor authentication off (removes the app and recovery codes). */
final class DisableTwoFactor extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/me/2fa');
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
    public function problems(): array
    {
        return [...parent::problems(), 404];
    }

    public function summary(): string
    {
        return 'Disable two-factor authentication';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();
        if (!$this->twoFactor->disable($userId)) {
            throw ApiProblem::notFound('Two-factor authentication is not enabled.');
        }

        Cms::instance()?->events->dispatch(new TwoFactorDisabled($userId, $userId));

        return null;
    }
}
