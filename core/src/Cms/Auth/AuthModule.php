<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Audit\AuditSubscriber;
use Kestello\Cms\Auth\External\AuthAttempts;
use Kestello\Cms\Auth\External\ExternalAuthController;
use Kestello\Cms\Auth\External\ExternalLogin;
use Kestello\Cms\Auth\External\Identities;
use Kestello\Cms\Auth\External\IdentityResolver;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\IdentityLinked;
use Kestello\Cms\Extension\Provider;
use Kestello\Contracts\Security\SecretStore;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Mail\Mailer;
use Kestello\Framework\Session\Session;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\AppKey;
use Kestello\Support\Html;

/**
 * Core module: login, second factor, password reset, email sign-up, and the
 * auth services the API endpoints use.
 *
 * Config (config/auth.php, returning ['password_min_length' => 12, ...]):
 *   password_min_length        12
 *   revoke_on_password_change  true   other sessions and all tokens end on a password change
 *   two_factor_roles           ['admin']  roles expected to use 2FA (reported now, enforced with the M3 enrollment screen)
 *   registration, registration_domains, default_role, link_by_verified_email, trust_provider_mfa
 *                              who may create an account (AccountPolicy; settings override)
 */
final class AuthModule extends Provider
{
    public function register(Container $container): void
    {
        $c = $container;
        $cms = static fn(): ?Cms => $c->has(Cms::class) ? $c->make(Cms::class) : null;
        $events = static fn(): ?EventDispatcher => $cms()?->events ?? ($c->has(EventDispatcher::class) ? $c->make(EventDispatcher::class) : null);
        $config = static fn(string $key, mixed $default): mixed => $cms()?->config->get('auth.' . $key, $default) ?? $default;

        $c->singleton(RateLimiter::class . '@auth', static fn(): RateLimiter => $c->has(RateLimiter::class) ? $c->make(RateLimiter::class) : new RateLimiter(new ArrayStore()));
        $c->singleton(LoginThrottle::class, static fn(): LoginThrottle => new LoginThrottle($c->make(RateLimiter::class . '@auth')));
        $c->singleton(TwoFactor::class, static fn(): TwoFactor => new TwoFactor(
            static fn(): SecretStore => $c->has(SecretStore::class) ? $c->make(SecretStore::class) : AppKey::secretStore(),
        ));
        $c->singleton(LoginService::class, static fn(): LoginService => new LoginService(
            static fn(): ?Auth => Auth::getInstance(),
            $c->make(LoginThrottle::class),
            $c->make(TwoFactor::class),
            $events(),
        ));
        $c->singleton(PasswordPolicy::class, static fn(): PasswordPolicy => new PasswordPolicy(
            max(8, (int) $config('password_min_length', 12)),
            $cms()?->filters ?? ($c->has(Filters::class) ? $c->make(Filters::class) : null),
        ));
        $c->singleton(AccountSecurity::class, static fn(): AccountSecurity => new AccountSecurity(
            static fn(): ?SessionStore => $c->has(SessionStore::class) ? $c->make(SessionStore::class) : null,
            new PersonalAccessTokens(),
            $events(),
            (bool) $config('revoke_on_password_change', true),
        ));
        if (!$c->has('auth.mail')) {
            $c->singleton('auth.mail', static fn(): Closure => static fn(string $to, string $subject, string $html): bool => (new Mailer())->send($to, $subject, $html));
        }
        $c->singleton(Identities::class, static fn(): Identities => new Identities());
        $c->singleton(NewAccounts::class, static fn(): NewAccounts => new NewAccounts(
            $cms()?->auth->roles ?? new Roles(),
            static fn(): AccountPolicy => AccountPolicy::current($cms()),
            $cms()?->filters,
        ));
        $c->singleton(IdentityResolver::class, static fn(): IdentityResolver => new IdentityResolver(
            $c->make(Identities::class),
            $c->make(NewAccounts::class),
            $events(),
        ));
        $c->singleton(ExternalLogin::class, static fn(): ExternalLogin => new ExternalLogin(
            $cms()?->auth ?? new AuthRegistry(),
            static fn(): ?Auth => Auth::getInstance(),
            static fn(): AuthAttempts => new AuthAttempts($c->has(Session::class) ? $c->make(Session::class) : new Session()),
            $c->make(IdentityResolver::class),
            $c->make(LoginService::class),
            $c->make(RateLimiter::class . '@auth'),
            static fn(): AccountPolicy => AccountPolicy::current($cms()),
            static fn(): string => self::site($cms())[1],
            $cms()?->filters,
            $events(),
        ));
        $c->singleton(EmailChanges::class, static function () use ($c, $cms): EmailChanges {
            [$name, $url] = self::site($cms());

            return new EmailChanges($c->make(RateLimiter::class . '@auth'), $c->make('auth.mail'), $url, $name);
        });
        $c->singleton(PasswordResets::class, static function () use ($c, $cms, $events): PasswordResets {
            [$name, $url] = self::site($cms());

            return new PasswordResets(
                $c->make(RateLimiter::class . '@auth'),
                $c->make(AccountSecurity::class),
                $c->make(PasswordPolicy::class),
                $c->make('auth.mail'),
                $url,
                $name,
                $events(),
            );
        });
        $c->singleton(Registrations::class, static function () use ($c, $cms, $events): Registrations {
            [$name, $url] = self::site($cms());

            return new Registrations(
                $c->make(RateLimiter::class . '@auth'),
                $c->make(NewAccounts::class),
                $c->make(PasswordPolicy::class),
                $c->make('auth.mail'),
                $url,
                $name,
                $events(),
            );
        });
    }

    public function boot(Cms $cms): void
    {
        (new AuditSubscriber($cms->audit))->subscribe($cms->events);
        $cms->events->on(IdentityLinked::class, static fn(IdentityLinked $e) => self::notifyLinked($cms, $e));
        $cms->events->on(\Kestello\Cms\Events\LoginFailed::class, static fn(\Kestello\Cms\Events\LoginFailed $e) => self::notifySecondFactorFailures($cms, $e));

        $r = $cms->routes;
        $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
        $r->post('/login', [AuthController::class, 'login'])->name('login.post');
        $r->get('/login/two-factor', [AuthController::class, 'showTwoFactor'])->name('login.two-factor');
        $r->post('/login/two-factor', [AuthController::class, 'twoFactor'])->name('login.two-factor.post');
        $r->post('/logout', [AuthController::class, 'logout'])->name('logout');
        // a public demo: one shared account nobody may take over, and no mail goes out
        if (!$cms->config->get('app.demo', false)) {
            $r->get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.forgot');
            $r->post('/forgot-password', [AuthController::class, 'forgot'])->name('password.forgot.post');
            $r->get('/reset-password', [AuthController::class, 'showReset'])->name('password.reset');
            $r->post('/reset-password', [AuthController::class, 'reset'])->name('password.reset.post');
            $r->get('/confirm-email', [AuthController::class, 'confirmEmail'])->name('email.confirm');
            $r->get('/register', [RegistrationController::class, 'show'])->name('register');
            $r->post('/register', [RegistrationController::class, 'register'])->name('register.post');
            $r->get('/register/complete', [RegistrationController::class, 'showComplete'])->name('register.complete');
            $r->post('/register/complete', [RegistrationController::class, 'complete'])->name('register.complete.post');
            $r->get('/auth/{provider}/redirect', [ExternalAuthController::class, 'redirect'])->where(['provider' => '[a-z0-9][a-z0-9-]{0,31}'])->name('auth.external.redirect');
            $r->get('/auth/{provider}/callback', [ExternalAuthController::class, 'callback'])->where(['provider' => '[a-z0-9][a-z0-9-]{0,31}'])->name('auth.external.callback');
        }
    }

    /** @return array{0: string, 1: string} site name and base URL */
    public static function site(?Cms $cms): array
    {
        $name = $url = null;
        try {
            $settings = Settings::getInstance();
            $name = $settings->get('site_name');
            $url = $settings->get('site_url');
        } catch (\Throwable) {
            // no database (tests, installer)
        }

        return [
            (string) ($name ?: $cms?->config->get('app.name', 'Kestello') ?: 'Kestello'),
            (string) ($url ?: $cms?->config->get('app.url', '') ?: ''),
        ];
    }

    /** Tell the owner a sign-in method was added to their account (not for brand-new accounts). */
    private static function notifyLinked(Cms $cms, IdentityLinked $e): void
    {
        if ($e->via === 'registration') {
            return;
        }
        try {
            $user = QueryBuilder::table('users')->where('id', $e->userId)->first();
            if ($user === null) {
                return;
            }
            [$site] = self::site($cms);
            $label = $cms->auth->findAuthenticator($e->provider)?->label() ?? $e->provider;
            $html = '<p>Hello ' . Html::escape((string) $user['username']) . ',</p>'
                . '<p>A ' . Html::escape($e->provider) . ' account was connected to your ' . Html::escape($site) . ' account ("' . Html::escape($label) . '"). It can now be used to sign in.</p>'
                . '<p>If this wasn\'t you, change your password and disconnect it from your profile right away.</p>';
            ($cms->container->make('auth.mail'))((string) $user['email'], "New sign-in method on {$site}", $html);
        } catch (\Throwable $ex) {
            error_log('[auth] could not send the account-linked email: ' . $ex->getMessage());
        }
    }

    /** Wrong second-factor codes that tell an account's owner their password is known. */
    public const int SECOND_FACTOR_WARNING = 5;

    /**
     * Tell the owner when someone keeps failing their second factor: that
     * person got past the password, so it should be changed. Once per
     * window, when the count reaches SECOND_FACTOR_WARNING.
     */
    private static function notifySecondFactorFailures(Cms $cms, \Kestello\Cms\Events\LoginFailed $e): void
    {
        if ($e->reason !== 'two_factor' || $e->userId === null
            || $cms->container->make(LoginThrottle::class)->failures('2fa', 'user:' . $e->userId) !== self::SECOND_FACTOR_WARNING) {
            return;
        }
        try {
            $user = QueryBuilder::table('users')->where('id', $e->userId)->first();
            if ($user === null) {
                return;
            }
            [$site] = self::site($cms);
            $html = '<p>Hello ' . Html::escape((string) $user['username']) . ',</p>'
                . '<p>Someone entered your ' . Html::escape($site) . ' password correctly, then a wrong two-factor code ' . self::SECOND_FACTOR_WARNING . ' times in the last hour. Two-factor authentication stopped them, but they know your password.</p>'
                . '<p>Sign in and change your password now. If you made these attempts yourself, you can ignore this email.</p>';
            ($cms->container->make('auth.mail'))((string) $user['email'], "Someone has your {$site} password", $html);
        } catch (\Throwable $ex) {
            error_log('[auth] could not send the two-factor warning email: ' . $ex->getMessage());
        }
    }

    /** Roles expected to use two-factor authentication. @return list<string> */
    public static function twoFactorRoles(?Cms $cms): array
    {
        return array_values(array_filter((array) ($cms?->config->get('auth.two_factor_roles', ['admin']) ?? ['admin']), 'is_string'));
    }
}
