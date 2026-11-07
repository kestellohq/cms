<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\PasswordResets;
use Kestello\Framework\Core\Response;

/** Email a reset link. Always answers 202, whether the account exists or not. */
final class ForgotPassword extends Endpoint
{
    public const string MESSAGE = 'If an account uses that address, we sent it a link to reset the password.';

    public function __construct(private readonly PasswordResets $resets) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/auth/password/forgot');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }
    public function summary(): string
    {
        return 'Request a password reset email';
    }
    public function tags(): array
    {
        return ['auth'];
    }

    public function body(): ?Schema
    {
        return Schema::object(['email' => Schema::string()->max(254)->format('email')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $this->resets->request((string) $request->input('email'), $request->http->ip());

        return Response::json(['data' => ['message' => self::MESSAGE]], 202);
    }
}
