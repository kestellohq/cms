<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Api\Endpoints\Auth\ForgotPassword;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\UserLoggedOut;
use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Session\Session;

/**
 * Server-rendered sign-in pages: password, second factor, forgotten
 * password and reset. The logic lives in LoginService/PasswordResets,
 * shared with the API.
 */
final class AuthController
{
    public function showLogin(Request $request): Response
    {
        $this->session()->token();   // the form posts with a CSRF token: start the session (guests browsing get none)
        return Page::render('@core/auth/Login', ['title' => 'Login', 'providers' => self::providers($request)]);
    }

    public function login(Request $request): Response
    {
        $form = LoginRequest::fromRequest($request);
        if (!$form->validate()) {
            return Response::redirect('/login');
        }

        $identifier = trim((string) $request->input('email', ''));
        $result = $this->service(LoginService::class)->attempt($identifier, (string) $request->input('password', ''), $request->ip());

        return match ($result->status) {
            LoginResult::SUCCESS => $this->welcome($result),
            LoginResult::TWO_FACTOR => Response::redirect('/login/two-factor'),
            LoginResult::THROTTLED => $this->back('/login', ['email' => [self::wait($result->retryAfter)]], ['email' => $identifier]),
            default => $this->back('/login', ['email' => ['Invalid email/username or password.']], ['email' => $identifier]),
        };
    }

    public function showTwoFactor(Request $request): Response
    {
        if (!$this->service(LoginService::class)->hasPendingChallenge()) {
            return Response::redirect('/login');
        }

        $this->session()->token();   // the form posts with a CSRF token: start the session (guests browsing get none)
        return Page::render('@core/auth/TwoFactorChallenge', ['title' => 'Two-factor authentication']);
    }

    public function twoFactor(Request $request): Response
    {
        $result = $this->service(LoginService::class)->verifySecondFactor((string) $request->input('code', ''), $request->ip());

        return match ($result->status) {
            LoginResult::SUCCESS => $this->welcome($result),
            LoginResult::EXPIRED => $this->back('/login', ['email' => ['Your sign-in attempt expired. Enter your password again.']]),
            LoginResult::THROTTLED => $this->back('/login/two-factor', ['code' => [self::wait($result->retryAfter)]]),
            default => $this->back('/login/two-factor', ['code' => ['That code is not correct.']]),
        };
    }

    public function logout(Request $request): Response
    {
        $auth = Auth::getInstance();
        $userId = isset($auth?->user()['id']) ? (int) $auth->user()['id'] : null;
        $auth?->logout();
        Cms::instance()?->events->dispatch(new UserLoggedOut($userId));

        return Response::redirect('/login');
    }

    public function showForgot(Request $request): Response
    {
        $this->session()->token();   // the form posts with a CSRF token: start the session (guests browsing get none)
        return Page::render('@core/auth/ForgotPassword', ['title' => 'Forgot password']);
    }

    public function forgot(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        if (!Emails::isValid($email)) {
            return $this->back('/forgot-password', ['email' => ['Enter a valid email address.']], ['email' => $email]);
        }

        $this->service(PasswordResets::class)->request($email, $request->ip());
        $this->session()->flash('success', ForgotPassword::MESSAGE);

        return Response::redirect('/forgot-password');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        $valid = $token !== '' && $this->service(PasswordResets::class)->find($token) !== null;

        // The token is in the URL: never leak it to other sites through Referer.
        $this->session()->token();   // the form posts with a CSRF token: start the session (guests browsing get none)
        return Page::render('@core/auth/ResetPassword', [
            'title' => 'Choose a new password',
            'token' => $valid ? $token : '',
            'valid' => $valid,
            'minLength' => $this->service(PasswordPolicy::class)->minLength(),
        ])->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');

        if ($password !== (string) $request->input('password_confirmation', '')) {
            return $this->back('/reset-password?token=' . rawurlencode($token), ['password' => ['The passwords don\'t match.']]);
        }

        $errors = $this->service(PasswordResets::class)->reset($token, $password, $request->ip());
        if ($errors !== []) {
            return $this->back('/reset-password?token=' . rawurlencode($token), array_map(static fn(array $m) => [implode(' ', $m)], $errors));
        }

        $this->session()->flash('success', 'Your password was changed. Sign in with the new one.');

        return Response::redirect('/login');
    }

    private function welcome(LoginResult $result): Response
    {
        $this->session()->flash('success', 'Welcome back!');
        if ($result->recoveryCodesLeft !== null) {
            $this->session()->flash('error', "You used a recovery code. {$result->recoveryCodesLeft} left: generate new ones soon.");
        }

        $caps = Cms::instance()?->auth->roles->capabilitiesFor((string) ($result->user['role'] ?? '')) ?? [];
        $admin = in_array('*', $caps, true) || in_array('admin.access', $caps, true);

        return Response::redirect($admin ? IntendedUrl::pull('/admin') : '/');
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, string>       $old
     */
    private function back(string $to, array $errors, array $old = []): Response
    {
        $this->session()->flash('_errors', $errors);
        if ($old !== []) {
            $this->session()->flash('_old_input', $old);
        }

        return Response::redirect($to);
    }

    /**
     * Sign-in buttons for registered providers.
     *
     * @return list<array{id: string, label: string, icon: ?string, url: string}>
     */
    private static function providers(Request $request): array
    {
        $returnTo = External\ReturnPath::sanitize($request->input('return_to'));
        $out = [];
        foreach (Cms::instance()?->auth->authenticators() ?? [] as $id => $authenticator) {
            if ($authenticator instanceof \Kestello\Contracts\Auth\RedirectAuthenticator) {
                $out[] = [
                    'id' => $id,
                    'label' => $authenticator->label(),
                    'icon' => $authenticator->icon(),
                    'url' => '/auth/' . $id . '/redirect' . ($returnTo !== null ? '?return_to=' . rawurlencode($returnTo) : ''),
                ];
            }
        }

        return $out;
    }

    private static function wait(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return 'Too many attempts. Try again in ' . ($seconds < 60 ? "{$seconds} seconds" : $minutes . ' minute' . ($minutes === 1 ? '' : 's')) . '.';
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        return (Cms::instance()?->container ?? Application::getInstance()->container())->make($class);
    }

    private function session(): Session
    {
        return Application::getInstance()->container()->make(Session::class);
    }
}
