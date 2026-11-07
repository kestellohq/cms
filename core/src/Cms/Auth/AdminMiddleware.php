<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/** Requires a signed-in user with the admin.access capability. */
final class AdminMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $cms = Cms::instance();
        $principal = $cms === null ? null : self::principal($cms, $request);
        if ($principal === null || $principal->isGuest()) {
            IntendedUrl::remember($request);
            return Response::redirect('/login');
        }
        if (!$principal->can('admin.access')) {
            return Response::html('', 403);
        }

        return $next($request);
    }

    /**
     * Who is asking for an admin page. The admin's HTML is for browser
     * sessions only: an API token (valid or not) never opens it, and a bad
     * one is a sign-in redirect, not an error.
     */
    public static function principal(Cms $cms, Request $request): \Kestello\Contracts\Auth\Principal
    {
        try {
            $principal = $cms->auth->authenticate($request);
        } catch (InvalidCredentials) {
            return \Kestello\Contracts\Auth\Principal::guest();
        }

        return $principal->scopes === null ? $principal : \Kestello\Contracts\Auth\Principal::guest();
    }
}
