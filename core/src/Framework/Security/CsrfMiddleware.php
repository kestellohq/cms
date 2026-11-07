<?php

declare(strict_types=1);

namespace Kestello\Framework\Security;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * CSRF validation middleware.
 *
 * Validates the CSRF token on state-changing HTTP methods (POST, PUT, PATCH, DELETE).
 * API routes and explicitly excluded URIs are skipped.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    private const array PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param Csrf          $csrf          CSRF helper instance.
     * @param array<string> $excludedPaths URI paths that bypass validation.
     * @param string        $apiPrefix     URI prefix for API routes (skipped).
     */
    public function __construct(
        private Csrf   $csrf,
        private array  $excludedPaths = [],
        private string $apiPrefix = '/api/',
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->shouldValidate($request)) {
            return $next($request);
        }

        $token = $request->input('_csrf_token') ?? $request->header('X-CSRF-TOKEN');

        if ($token === null || !$this->csrf->validateToken((string) $token)) {
            return Response::html('<h1>403 Forbidden</h1><p>CSRF token validation failed.</p>', 403);
        }

        return $next($request);
    }

    private function shouldValidate(Request $request): bool
    {
        if (!in_array(strtoupper($request->method), self::PROTECTED_METHODS, true)) {
            return false;
        }

        if (str_starts_with($request->path, $this->apiPrefix)) {
            return false;
        }

        foreach ($this->excludedPaths as $excluded) {
            // Exact, or a whole path segment below it: "/system/cron" never covers "/system/cron-x".
            if ($request->path === $excluded || str_starts_with($request->path, rtrim($excluded, '/') . '/')) {
                return false;
            }
        }

        return true;
    }
}
