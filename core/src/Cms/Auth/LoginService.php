<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Events\LoginFailed;
use Kestello\Cms\Events\UserLoggedIn;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Support\Hash;

/**
 * The password login flow, shared by the login form and the API:
 * throttling, timing-safe lookup, rehashing, the second-factor step and
 * events. Callers only render the LoginResult.
 *
 * @api
 */
final class LoginService
{
    /** Wrong codes allowed per pending challenge before the password is needed again. */
    public const int MAX_CODE_ATTEMPTS = 5;

    /** @param Closure(): ?Auth $auth */
    public function __construct(
        private readonly Closure $auth,
        private readonly LoginThrottle $throttle,
        private readonly TwoFactor $twoFactor,
        private readonly ?EventDispatcher $events = null,
    ) {}

    /** First factor: email or username, and password. */
    public function attempt(string $identifier, #[\SensitiveParameter] string $password, string $ip): LoginResult
    {
        $identifier = trim($identifier);
        $user = $identifier === '' ? null : $this->findUser($identifier);
        $account = LoginThrottle::account($user, $identifier);

        $wait = $this->throttle->reserve('login', $ip, $account);
        if ($wait > 0) {
            $this->fail($identifier, $user, 'throttled', $ip);
            return LoginResult::throttled($wait);
        }

        if ($user === null) {
            Hash::verifyDummy($password);
            $valid = false;
        } else {
            $valid = Hash::verify($password, (string) $user['password']) && (bool) ($user['is_active'] ?? true);
        }

        if (!$valid) {
            $this->fail($identifier, $user, 'credentials', $ip);
            return LoginResult::failed();
        }

        $this->throttle->succeeded('login', $ip, $account);
        if (Hash::needsRehash((string) $user['password'])) {
            $user['password'] = Hash::password($password);
            QueryBuilder::table('users')->where('id', $user['id'])->update(['password' => $user['password']]);
        }

        if ($this->twoFactor->isEnabled((int) $user['id'])) {
            $this->auth()->beginSecondFactor((int) $user['id'], 'password');
            return LoginResult::twoFactor();
        }

        return $this->complete($user, 'password');
    }

    /**
     * Finish an external sign-in for a resolved user: the second factor
     * still applies unless the provider asserted MFA and the policy trusts it.
     *
     * @param array<string, mixed> $user
     */
    public function completeExternal(array $user, string $provider, bool $providerMfa = false, bool $trustProviderMfa = false): LoginResult
    {
        if (!(bool) ($user['is_active'] ?? true)) {
            $this->fail($provider, $user, 'inactive', '');
            return LoginResult::failed();
        }

        $method = 'external:' . $provider;
        if ($this->twoFactor->isEnabled((int) $user['id']) && !($providerMfa && $trustProviderMfa)) {
            $this->auth()->beginSecondFactor((int) $user['id'], $method);
            return LoginResult::twoFactor();
        }

        return $this->complete($user, $method . ($providerMfa ? '+provider-mfa' : ''));
    }

    /**
     * Sign in someone who has just created their account by email sign-up.
     * They chose the password a moment ago, and a new account has no second
     * factor yet.
     *
     * @param array<string, mixed> $user
     */
    public function completeRegistration(array $user): LoginResult
    {
        return $this->complete($user, 'password');
    }

    /** Whether a second-factor challenge is waiting in this session. */
    public function hasPendingChallenge(): bool
    {
        return $this->auth()->pendingSecondFactor() !== null;
    }

    /** Second factor: a TOTP code or a recovery code. */
    public function verifySecondFactor(#[\SensitiveParameter] string $code, string $ip): LoginResult
    {
        $auth = $this->auth();
        $pending = $auth->pendingSecondFactor();
        if ($pending === null) {
            return LoginResult::expired();
        }

        $userId = (int) $pending['user_id'];
        $account = 'user:' . $userId;
        $wait = $this->throttle->reserve('2fa', $ip, $account);
        if ($wait > 0) {
            return LoginResult::throttled($wait);
        }

        $user = QueryBuilder::table('users')->where('id', $userId)->first();
        if ($user === null || !(bool) ($user['is_active'] ?? true) || !$auth->pendingStillValid($user)) {
            $auth->clearSecondFactor();
            return LoginResult::expired();
        }

        $used = $this->twoFactor->verify($userId, $code);
        if ($used === null) {
            $this->fail((string) $user['username'], $user, 'two_factor', $ip);
            if ($auth->failSecondFactor() >= self::MAX_CODE_ATTEMPTS) {
                $auth->clearSecondFactor();
                return LoginResult::expired();
            }
            return LoginResult::failed();
        }

        $this->throttle->succeeded('2fa', $ip, $account);
        $left = $used === 'recovery' ? $this->twoFactor->recoveryCodesLeft($userId) : null;

        return $this->complete($user, $pending['method'] . '+' . $used, $left);
    }

    /** Re-enter the password for a sensitive action ("sudo mode"). */
    public function confirmPassword(int $userId, #[\SensitiveParameter] string $password, string $ip): LoginResult
    {
        $account = 'user:' . $userId;
        $wait = $this->throttle->reserve('confirm', $ip, $account);
        if ($wait > 0) {
            return LoginResult::throttled($wait);
        }

        $user = QueryBuilder::table('users')->where('id', $userId)->first();
        if ($user === null || !Hash::verify($password, (string) $user['password'])) {
            return LoginResult::failed();
        }

        $this->throttle->succeeded('confirm', $ip, $account);
        $this->auth()->confirm();

        return LoginResult::success($user, (string) ($this->auth()->method() ?? 'password'));
    }

    /** @param array<string, mixed> $user */
    private function complete(array $user, string $method, ?int $recoveryCodesLeft = null): LoginResult
    {
        $this->auth()->login($user, $method);
        $result = LoginResult::success($user, $method, $recoveryCodesLeft);
        $this->events?->dispatch(new UserLoggedIn((int) $user['id'], (array) $result->user, $method));

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function findUser(string $identifier): ?array
    {
        $column = str_contains($identifier, '@') ? 'email' : 'username';
        $value = $column === 'email' ? Emails::normalize($identifier) : $identifier;

        return QueryBuilder::table('users')->where($column, $value)->first();
    }

    /** @param array<string, mixed>|null $user */
    private function fail(string $identifier, ?array $user, string $reason, string $ip): void
    {
        $this->events?->dispatch(new LoginFailed($identifier, $user === null ? null : (int) $user['id'], $reason, $ip));
    }

    private function auth(): Auth
    {
        return ($this->auth)() ?? throw new \LogicException('Logging in needs a session.');
    }
}
