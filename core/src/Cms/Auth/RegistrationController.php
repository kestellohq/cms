<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Cms;
use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Session\Session;

/**
 * Server-rendered email sign-up: the address form, then the page the
 * emailed link opens, where the password is chosen. While sign-up is
 * closed (Settings → Accounts) these addresses don't exist. The logic
 * lives in Registrations.
 */
final class RegistrationController
{
    public function show(Request $request): Response
    {
        if (!$this->registrations()->isOpen()) {
            return Response::notFound();
        }
        if (Auth::getInstance()?->check() === true) {
            return Response::redirect('/');
        }

        $this->session()->token();   // the form posts with a CSRF token: start the session (guests browsing get none)
        return Page::render('@core/auth/Register', ['title' => 'Create an account', 'domains' => $this->registrations()->domains()]);
    }

    public function register(Request $request): Response
    {
        if (!$this->registrations()->isOpen()) {
            return Response::notFound();
        }

        $email = trim((string) $request->input('email', ''));
        $errors = $this->registrations()->request($email, $request->ip());
        if ($errors !== []) {
            return $this->back('/register', $errors, ['email' => $email]);
        }

        $this->session()->flash('success', Registrations::SENT);

        return Response::redirect('/register');
    }

    public function showComplete(Request $request): Response
    {
        if (!$this->registrations()->isOpen()) {
            return Response::notFound();
        }

        $token = (string) $request->input('token', '');
        $email = $token !== '' ? $this->registrations()->find($token) : null;

        // The token is in the URL: never leak it to other sites through Referer.
        $this->session()->token();   // the form posts with a CSRF token: start the session (guests browsing get none)
        return Page::render('@core/auth/CompleteRegistration', [
            'title' => 'Choose your password',
            'token' => $email !== null ? $token : '',
            'email' => $email ?? '',
            'valid' => $email !== null,
            'minLength' => $this->service(PasswordPolicy::class)->minLength(),
        ])->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function complete(Request $request): Response
    {
        if (!$this->registrations()->isOpen()) {
            return Response::notFound();
        }

        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');
        $again = '/register/complete?token=' . rawurlencode($token);
        if ($password !== (string) $request->input('password_confirmation', '')) {
            return $this->back($again, ['password' => ['The passwords don\'t match.']]);
        }

        ['errors' => $errors, 'user' => $user] = $this->registrations()->complete($token, $password, $request->ip());
        if ($user === null) {
            return $this->back($again, array_map(static fn(array $m) => [implode(' ', $m)], $errors));
        }

        $this->service(LoginService::class)->completeRegistration($user);
        $this->session()->flash('success', 'Welcome! Your account is ready.');

        $cms = Cms::instance();

        return Response::redirect($cms === null ? '/' : AfterSignIn::path($user, null, $cms->auth->roles, $cms->filters));
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

    private function registrations(): Registrations
    {
        return $this->service(Registrations::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        return (Cms::instance()->container ?? Application::getInstance()->container())->make($class);
    }

    private function session(): Session
    {
        return Application::getInstance()->container()->make(Session::class);
    }
}
