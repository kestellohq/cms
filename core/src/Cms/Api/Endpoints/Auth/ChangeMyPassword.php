<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\LoginResult;
use Kestello\Cms\Auth\LoginService;
use Kestello\Cms\Auth\PasswordPolicy;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Change your password (current password required). Other sessions and all
 * personal access tokens end, unless auth.revoke_on_password_change is off.
 */
final class ChangeMyPassword extends Endpoint
{
    use SessionOnly;

    public function __construct(
        private readonly LoginService $login,
        private readonly AccountSecurity $accounts,
        private readonly PasswordPolicy $policy,
    ) {}

    public function route(): ApiRoute
    {
        return ApiRoute::put('/me/password');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }
    public function summary(): string
    {
        return 'Change your password';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'current_password' => Schema::string()->min(1)->max(4096),
            'password' => Schema::string()->min(1)->max(4096),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();
        $confirmed = $this->login->confirmPassword($userId, (string) $request->input('current_password'), $request->http->ip());
        if ($confirmed->status === LoginResult::THROTTLED) {
            throw ApiProblem::tooManyRequests($confirmed->retryAfter);
        }
        if (!$confirmed->ok()) {
            throw ApiProblem::validation(['current_password' => ['Your current password is not correct.']]);
        }

        $user = (array) QueryBuilder::table('users')->where('id', $userId)->first();
        $password = (string) $request->input('password');
        $errors = $this->policy->errors($password, $user);
        if ($errors !== []) {
            throw ApiProblem::validation(['password' => $errors]);
        }

        $changed = $this->accounts->changePassword($userId, $password, 'profile', $request->principal->credentialId);
        $fresh = QueryBuilder::table('users')->where('id', $userId)->first();
        if ($fresh !== null) {
            Auth::getInstance()?->refreshPassword($fresh);
        }

        return ['sessions_revoked' => $changed->sessionsRevoked, 'tokens_revoked' => $changed->tokensRevoked];
    }
}
