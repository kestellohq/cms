<?php

declare(strict_types=1);

namespace Kestello\Cms\Redirects;

use Closure;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * After the site answered 404 to a page request: follow a redirect rule if
 * one matches, otherwise remember the address in the "not found" log.
 * API, asset and admin requests are left alone.
 */
final class RedirectMiddleware implements MiddlewareInterface
{
    private const array SKIP = ['/api/', '/assets/', '/admin/', '/uploads/', '/system/'];

    public function __construct(private readonly Redirects $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->statusCode() !== 404 || !in_array($request->method, ['GET', 'HEAD'], true) || $this->skipped($request->path)) {
            return $response;
        }

        $path = ContentLanguages::localize($request->path, ContentLanguages::current());     // as asked, with its language prefix
        try {
            $rule = $this->redirects->resolve($path, (string) (parse_url($request->uri, PHP_URL_QUERY) ?? ''));
            if ($rule === null) {
                if (!self::looksLikeAsset($path)) {
                    $this->redirects->logNotFound($path, $request->header('Referer'));
                }
                return $response;
            }
            $this->redirects->hit($rule['id']);
        } catch (\Throwable $e) {
            error_log('[redirects] ' . $e->getMessage());     // never turn a 404 into a 500
            return $response;
        }

        if ($rule['status'] === 410) {
            return $response->withStatus(410);
        }

        return Response::redirect($rule['target'], $rule['status']);
    }

    private function skipped(string $path): bool
    {
        foreach (self::SKIP as $prefix) {
            if (str_starts_with($path . '/', $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Bots probe for files (wp-login.php, .env, favicon sizes); keep them out of the editors' list. */
    private static function looksLikeAsset(string $path): bool
    {
        return preg_match('~(^|/)\.|\.(php\d?|asp|aspx|jsp|cgi|env|ini|log|sql|bak|zip|gz|tar|ico|png|jpe?g|gif|webp|svg|css|js|map|xml|txt|json)$~i', $path) === 1;
    }
}
