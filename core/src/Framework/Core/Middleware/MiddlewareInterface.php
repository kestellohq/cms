<?php

declare(strict_types=1);

namespace Kestello\Framework\Core\Middleware;

use Closure;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Contract that all HTTP middleware must implement.
 */
interface MiddlewareInterface
{
    /**
     * Process the incoming request and return a response.
     *
     * The middleware may:
     *  - modify the request and pass it through: `return $next($request);`
     *  - short-circuit by returning a Response directly
     *  - modify the response after the inner handler has run
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure(Request): Response $next The next handler in the pipeline.
     */
    public function handle(Request $request, Closure $next): Response;
}
