<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\LoginService;

/**
 * Password login for same-origin SPAs and Vue pages: sets the session
 * cookie. Needs the CSRF header like any cookie request. When the account
 * has 2FA the answer is {two_factor_required: true}; continue with
 * POST /auth/2fa/verify.
 */
final class CreateSession extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly LoginService $login) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/auth/session');
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
        return 'Sign in with a password (session cookie)';
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
        return Schema::object([
            'login' => Schema::string()->min(1)->max(255)->description('Email or username'),
            'password' => Schema::string()->min(1)->max(4096),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::loginResponse($this->login->attempt((string) $request->input('login'), (string) $request->input('password'), $request->http->ip()));
    }
}
