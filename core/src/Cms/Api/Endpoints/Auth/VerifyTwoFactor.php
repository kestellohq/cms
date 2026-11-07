<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\LoginService;

/** Second sign-in step: a code from the authenticator app, or a recovery code. */
final class VerifyTwoFactor extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly LoginService $login) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/auth/2fa/verify');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [30, 60];
    }
    public function summary(): string
    {
        return 'Complete sign-in with a two-factor code';
    }
    public function tags(): array
    {
        return ['auth'];
    }
    public function authorize(ApiRequest $request): bool
    {
        return true;
    }

    public function body(): ?Schema
    {
        return Schema::object(['code' => Schema::string()->min(6)->max(20)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::loginResponse($this->login->verifySecondFactor((string) $request->input('code'), $request->http->ip()));
    }
}
