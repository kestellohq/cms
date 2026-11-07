<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Serves the page chosen under Settings → Reading at the site address, ahead
 * of the theme's own "/" route. Without a choice (or when that page isn't
 * published) the theme's home page answers as before.
 */
final class FrontPageMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Cms $cms) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD'], true) && $request->path === '/') {
            $home = ContentController::home($this->cms, $request);
            if ($home !== null) {
                return $home;
            }
        }

        return $next($request);
    }
}
