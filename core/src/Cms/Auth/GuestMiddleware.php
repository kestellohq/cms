<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Middleware that only allows unauthenticated (guest) users.
 *
 * Redirects authenticated users to the home page.
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = Auth::getInstance();

        if ($auth !== null && $auth->check()) {
            return Response::redirect('/');
        }

        return $next($request);
    }
}
