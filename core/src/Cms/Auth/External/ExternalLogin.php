<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Closure;
use Kestello\Cms\Auth\AccountPolicy;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Auth\LoginResult;
use Kestello\Cms\Auth\LoginService;
use Kestello\Cms\Events\LoginFailed;
use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\AuthenticationFailed;
use Kestello\Contracts\Auth\RedirectAuthenticator;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\RateLimiter;

/**
 * The redirect sign-in flow (auth.md §4), provider-independent:
 *
 *   begin()  rate limit → AuthAttempt (state, nonce, PKCE) in the session → provider URL
 *   finish() consume the attempt by state → provider complete() (as its plugin)
 *            → IdentityResolver → second factor → session → validated return path
 *
 * Linking from the profile uses intent "link" and needs a sign-in within
 * the last 10 minutes.
 *
 * @api
 */
final class ExternalLogin
{
    public const int RECENT_AUTH = 600;
    private const int LIMIT = 20;
    private const int WINDOW = 600;

    /**
     * @param Closure(): ?Auth              $auth
     * @param Closure(): AccountPolicy     $policy
     * @param Closure(): string              $baseUrl  site URL for redirect URIs
     */
    public function __construct(
        private readonly AuthRegistry $registry,
        private readonly Closure $auth,
        private readonly Closure $attempts,
        private readonly IdentityResolver $resolver,
        private readonly LoginService $login,
        private readonly RateLimiter $limiter,
        private readonly Closure $policy,
        private readonly Closure $baseUrl,
        private readonly ?Filters $filters = null,
        private readonly ?EventDispatcher $events = null,
    ) {}

    public function redirectUri(string $id): string
    {
        return rtrim(($this->baseUrl)(), '/') . '/auth/' . $id . '/callback';
    }

    /**
     * Start: returns the provider URL to redirect to, or a failed result.
     */
    public function begin(string $id, string $intent, mixed $returnTo, string $ip): ExternalLoginResult
    {
        $authenticator = $this->registry->findAuthenticator($id);
        if (!$authenticator instanceof RedirectAuthenticator) {
            return ExternalLoginResult::failed('That sign-in method is not available.');
        }
        if (!$this->limiter->hit('ext-login:' . $ip, self::LIMIT, self::WINDOW)->allowed) {
            return ExternalLoginResult::failed('Too many sign-in attempts. Try again in a few minutes.');
        }

        $userId = null;
        if ($intent === 'link') {
            $auth = $this->auth();
            $userId = $auth->id() !== null ? (int) $auth->id() : null;
            if ($userId === null) {
                return ExternalLoginResult::failed('Sign in first to connect an account.');
            }
            if (time() - (int) ($auth->authenticatedAt() ?? 0) > self::RECENT_AUTH) {
                return ExternalLoginResult::failed('For your security, confirm your password before connecting an account.', ReturnPath::sanitize($returnTo) ?? '/');
            }
        } elseif ($intent !== 'login') {
            return ExternalLoginResult::failed('That sign-in method is not available.');
        }

        $attempt = AuthAttempt::start($id, $intent, $this->redirectUri($id), ReturnPath::sanitize($returnTo), $userId);
        try {
            $url = $this->registry->runAuthenticator($id, static fn(): string => $authenticator->authorizationUrl($attempt));
        } catch (AuthenticationFailed $e) {
            $this->log($id, $e->detail ?? $e->getMessage());
            return ExternalLoginResult::failed($e->getMessage());
        } catch (\Throwable $e) {
            $this->log($id, $e::class . ': ' . $e->getMessage());
            return ExternalLoginResult::failed('Sign-in with this provider is not working right now.');
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            $this->log($id, 'authorization URL is not https');
            return ExternalLoginResult::failed('Sign-in with this provider is not working right now.');
        }

        $this->attempts()->put($attempt);

        return new ExternalLoginResult(ExternalLoginResult::SUCCESS, $url);
    }

    /** Callback from the provider. */
    public function finish(string $id, Request $request): ExternalLoginResult
    {
        $ip = $request->ip();
        if (!$this->limiter->hit('ext-callback:' . $ip, self::LIMIT, self::WINDOW)->allowed) {
            return ExternalLoginResult::failed('Too many sign-in attempts. Try again in a few minutes.');
        }

        $attempt = $this->attempts()->pull((string) $request->input('state', ''));
        if ($attempt === null || $attempt->provider !== $id) {
            return ExternalLoginResult::failed('This sign-in link expired or was already used. Please try again.');
        }
        $failTo = $attempt->intent === 'link' ? ($attempt->returnTo ?? '/') : '/login';

        $authenticator = $this->registry->findAuthenticator($id);
        if (!$authenticator instanceof RedirectAuthenticator) {
            return ExternalLoginResult::failed('That sign-in method is not available.', $failTo);
        }

        try {
            $identity = $this->registry->runAuthenticator($id, static fn() => $authenticator->complete($request, $attempt));
        } catch (AuthenticationFailed $e) {
            $this->log($id, $e->detail ?? $e->getMessage());
            $this->events?->dispatch(new LoginFailed($id, null, 'external', $ip));
            return ExternalLoginResult::failed($e->getMessage(), $failTo);
        } catch (\Throwable $e) {
            $this->log($id, $e::class . ': ' . $e->getMessage());
            $this->events?->dispatch(new LoginFailed($id, null, 'external', $ip));
            return ExternalLoginResult::failed('Sign-in failed. Please try again.', $failTo);
        }

        if ($identity->provider !== $id) {
            $this->log($id, "identity claims provider \"{$identity->provider}\"");
            return ExternalLoginResult::failed('Sign-in failed. Please try again.', $failTo);
        }

        if ($attempt->intent === 'link') {
            $auth = $this->auth();
            if ($attempt->userId === null || (int) ($auth->id() ?? 0) !== $attempt->userId) {
                return ExternalLoginResult::failed('Your session changed. Sign in and try again.');
            }
            $resolution = $this->resolver->link($attempt->userId, $identity);
            if (!$resolution->ok()) {
                return ExternalLoginResult::failed($resolution->message, $failTo);
            }

            return new ExternalLoginResult(ExternalLoginResult::LINKED, $attempt->returnTo ?? '/', $authenticator->label() . ' is now connected.', $resolution->user);
        }

        $resolution = $this->resolver->resolve($identity, $authenticator);
        if (!$resolution->ok()) {
            $this->events?->dispatch(new LoginFailed($id, null, 'external_refused', $ip));
            return ExternalLoginResult::failed($resolution->message);
        }

        $policy = ($this->policy)();
        $result = $this->login->completeExternal((array) $resolution->user, $id, $identity->mfa, $policy->trustProviderMfa);
        $registered = $resolution->status === Resolution::REGISTERED;

        return match ($result->status) {
            LoginResult::SUCCESS => new ExternalLoginResult(ExternalLoginResult::SUCCESS, $this->afterLogin($result->user ?? [], $attempt), $registered ? 'Welcome! Your account was created.' : 'Welcome back!', $result->user, $registered),
            LoginResult::TWO_FACTOR => new ExternalLoginResult(ExternalLoginResult::TWO_FACTOR, '/login/two-factor'),
            default => ExternalLoginResult::failed('This account is disabled.'),
        };
    }

    /** @param array<string, mixed> $user */
    private function afterLogin(array $user, AuthAttempt $attempt): string
    {
        $default = $attempt->returnTo ?? (($user['role'] ?? '') === 'admin' ? '/admin' : '/');
        $filtered = $this->filters?->apply('auth.redirect_after_login', $default, $user) ?? $default;

        return ReturnPath::sanitize($filtered) ?? $default; // re-validated: a filter can't send users off-site
    }

    private function log(string $id, string $detail): void
    {
        error_log("[auth:{$id}] external sign-in failed: " . mb_substr($detail, 0, 500));
    }

    private function auth(): Auth
    {
        return ($this->auth)() ?? throw new \LogicException('External sign-in needs a session.');
    }

    private function attempts(): AuthAttempts
    {
        return ($this->attempts)();
    }
}
