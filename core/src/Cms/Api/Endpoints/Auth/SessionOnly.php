<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Auth\LoginResult;
use Kestello\Framework\Core\Response;

/** Shared bits of the auth endpoints. @internal */
trait SessionOnly
{
    /** Account-security actions are refused for API tokens, whatever their scopes. */
    public function authorize(ApiRequest $request): bool
    {
        if ($request->principal->method !== 'session') {
            throw ApiProblem::forbidden('This action requires an interactive session.');
        }

        return true;
    }

    private static function loginResponse(LoginResult $result): Response
    {
        return match ($result->status) {
            LoginResult::SUCCESS => Response::json(['data' => [
                'two_factor_required' => false,
                'user' => $result->user,
                'recovery_codes_left' => $result->recoveryCodesLeft,
                'csrf_token' => self::csrfToken(),
            ]]),
            LoginResult::TWO_FACTOR => Response::json(['data' => ['two_factor_required' => true, 'csrf_token' => self::csrfToken()]]),
            LoginResult::THROTTLED => throw ApiProblem::tooManyRequests($result->retryAfter),
            LoginResult::EXPIRED => throw new ApiProblem(401, 'two_factor_expired', 'Sign in again', 'The sign-in attempt expired. Enter your password again.'),
            default => throw ApiProblem::invalidCredentials(),
        };
    }

    /** The session id changed, and so may the CSRF token: hand the client the current one. */
    private static function csrfToken(): ?string
    {
        $c = \Kestello\Cms\Cms::instance()?->container;

        return $c !== null && $c->has(\Kestello\Framework\Session\Session::class) && session_status() === PHP_SESSION_ACTIVE
            ? $c->make(\Kestello\Framework\Session\Session::class)->token()
            : null;
    }
}
