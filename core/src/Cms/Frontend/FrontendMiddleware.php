<?php

declare(strict_types=1);

namespace Kestello\Cms\Frontend;

use Closure;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\PageResponse;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * - Client navigation with stale assets → 409 + X-Kestello-Location (full reload)
 * - Page responses → HTML shell or page JSON
 * - Redirects after PUT/PATCH/DELETE from the router → 303 (so the follow-up is a GET)
 * - HTML responses → Content-Security-Policy with the request nonce
 */
final readonly class FrontendMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Csp $csp,
        private PageRenderer $pages,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $navigating = $request->header('X-Kestello') === 'true';

        if ($navigating && $request->method === 'GET'
            && $request->header('X-Kestello-Version') !== ''
            && $request->header('X-Kestello-Version') !== $this->pages->registry()->version()) {
            return (new Response('', 409))->withHeader('X-Kestello-Location', $request->uri);
        }

        $response = $next($request);

        $payload = $response->payload();
        if ($payload instanceof PageResponse) {
            $response = $this->pages->render($request, $payload, $response);
        }

        if ($navigating && $response->statusCode() === 302 && in_array($request->method, ['PUT', 'PATCH', 'DELETE'], true)) {
            $response = $response->withStatus(303);
        }

        return $this->withCsp($response);
    }

    public function withCsp(Response $response): Response
    {
        foreach ($response->headers() as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'content-security-policy') {
                return $response;
            }
            if ($lower === 'content-type' && !str_starts_with(strtolower($value), 'text/html')) {
                return $response;
            }
        }

        return $response->withHeader('Content-Security-Policy', $this->csp->header());
    }
}
