<?php

declare(strict_types=1);

namespace Kestello\Framework\Http;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * CORS for the API. Off unless origins are allowlisted (api.cors.origins).
 * A wildcard origin never comes with credentials.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $origins exact origins ("https://app.example.com") or "*"
     */
    public function __construct(
        private readonly array $origins = [],
        private readonly bool $credentials = false,
        private readonly int $maxAge = 600,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $allowed = $this->allowedOrigin($origin);

        if ($request->isMethod('OPTIONS') && $request->header('Access-Control-Request-Method') !== '') {
            $response = new Response('', 204);

            return $allowed === null ? $response : $this->decorate($response, $allowed)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, If-Match, Idempotency-Key, X-CSRF-TOKEN')
                ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }

        $response = $next($request);

        return $allowed === null ? $response : $this->decorate($response, $allowed);
    }

    private function allowedOrigin(string $origin): ?string
    {
        if ($origin === '' || $this->origins === []) {
            return null;
        }

        if (in_array($origin, $this->origins, true)) {
            return $origin;
        }

        return in_array('*', $this->origins, true) ? '*' : null;
    }

    private function decorate(Response $response, string $origin): Response
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Expose-Headers', 'ETag, RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset, Retry-After, X-Request-Id')
            ->withHeader('Vary', 'Origin');

        if ($this->credentials && $origin !== '*') {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
