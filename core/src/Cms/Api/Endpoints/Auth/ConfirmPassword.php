<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\LoginResult;
use Kestello\Cms\Auth\LoginService;
use Kestello\Framework\Core\Response;

/**
 * Re-enter the password before a sensitive action. Endpoints that answer
 * 401 reauth_required work for the next 10 minutes after this.
 */
final class ConfirmPassword extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly LoginService $login) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/auth/confirm');
    }
    public function capability(): ?string
    {
        return 'read';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function rateLimit(): ?array
    {
        return [20, 60];
    }
    public function summary(): string
    {
        return 'Confirm your password (recent authentication)';
    }
    public function tags(): array
    {
        return ['auth'];
    }

    public function body(): ?Schema
    {
        return Schema::object(['password' => Schema::string()->min(1)->max(4096)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $result = $this->login->confirmPassword((int) $request->userId(), (string) $request->input('password'), $request->http->ip());
        if ($result->status !== LoginResult::SUCCESS) {
            return self::loginResponse($result);
        }

        return Response::json(['data' => ['confirmed_until' => time() + 600]]);
    }
}
