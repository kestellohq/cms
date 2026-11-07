<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\CorsMiddleware;

/**
 * Wraps every /api/ request: CORS (including preflights, which have no
 * route) and problem+json for paths no endpoint matched.
 */
final class ApiGateway implements MiddlewareInterface
{
    public function __construct(private readonly CorsMiddleware $cors) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!str_starts_with($request->path, '/api/')) {
            return $next($request);
        }

        return $this->cors->handle($request, static function (Request $request) use ($next): Response {
            $response = $next($request);

            if ($response->statusCode() === 404 && !str_contains($response->headers()['Content-Type'] ?? '', 'json')) {
                return ApiProblem::notFound('No endpoint matches ' . $request->method . ' ' . $request->path . '.')->toResponse();
            }

            return $response;
        });
    }
}
