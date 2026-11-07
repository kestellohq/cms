<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Middleware that requires an authenticated user.
 *
 * Redirects guests to the login page (web) or returns 401 (API).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = Auth::getInstance();

        if ($auth === null || $auth->guest()) {
            if (str_starts_with($request->path, '/api/')) {
                return Response::json(['error' => 'Unauthenticated.'], 401);
            }

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
