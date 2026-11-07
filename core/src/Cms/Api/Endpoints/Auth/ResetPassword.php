<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\PasswordResets;

/** Set a new password with the emailed token. Does not sign in. */
final class ResetPassword extends Endpoint
{
    public function __construct(private readonly PasswordResets $resets) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/auth/password/reset');
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
        return 'Reset a password with an emailed token';
    }
    public function tags(): array
    {
        return ['auth'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'token' => Schema::string()->min(1)->max(100),
            'password' => Schema::string()->min(1)->max(4096),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $errors = $this->resets->reset((string) $request->input('token'), (string) $request->input('password'), $request->http->ip());
        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }

        return null;
    }
}
