<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Kestello\Cms\Cms;
use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Session\Session;

/**
 * GET  /auth/{provider}/redirect?return_to=/path[&intent=link]
 * GET  /auth/{provider}/callback
 *
 * Starting is a GET (it's a link on the login page) but changes nothing
 * except the visitor's own session; linking needs a recent sign-in, and the
 * callback is protected by the single-use state.
 */
final class ExternalAuthController
{
    public function redirect(Request $request, string $provider): Response
    {
        $intent = (string) $request->input('intent', 'login') === 'link' ? 'link' : 'login';
        $result = $this->service()->begin($provider, $intent, $request->input('return_to'), $request->ip());

        if ($result->status === ExternalLoginResult::FAILED) {
            $this->session()->flash('error', $result->message);
            return Response::redirect($result->redirect);
        }

        // The provider URL is built by core from the authenticator's https endpoint.
        return Response::redirect($result->redirect)->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, string $provider): Response
    {
        $result = $this->service()->finish($provider, $request);

        if ($result->message !== '') {
            $this->session()->flash($result->status === ExternalLoginResult::FAILED ? 'error' : 'success', $result->message);
        }

        // The callback URL carries the code: keep it out of Referer headers.
        return Response::redirect($result->redirect)->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function service(): ExternalLogin
    {
        return (Cms::instance()?->container ?? Application::getInstance()->container())->make(ExternalLogin::class);
    }

    private function session(): Session
    {
        return Application::getInstance()->container()->make(Session::class);
    }
}
