<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\TwoFactor;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\TwoFactorEnabled;

/** Finish enrollment with a code from the app. Returns recovery codes, once. */
final class ConfirmTotp extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/me/2fa/totp/confirm');
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
        return [10, 300];
    }
    public function summary(): string
    {
        return 'Confirm authenticator-app enrollment';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function body(): ?Schema
    {
        return Schema::object(['code' => Schema::string()->min(6)->max(10)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $codes = $this->twoFactor->confirmTotp((int) $request->userId(), (string) $request->input('code'));
        if ($codes === null) {
            throw ApiProblem::validation(['code' => ['That code is not correct (or enrollment expired; start again).']]);
        }

        Cms::instance()?->events->dispatch(new TwoFactorEnabled((int) $request->userId(), 'totp'));

        return ['recovery_codes' => $codes];
    }
}
