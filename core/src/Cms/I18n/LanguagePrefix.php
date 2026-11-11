<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Closure;
use Kestello\Cms\Content\Entries;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Answers /es/… in Spanish: when a path starts with the code of one of the
 * site's other languages (ContentLanguages), the rest of the site sees the
 * path without it and runs in that language. Everything after this
 * middleware (the page cache, redirects, routes) knows the language from
 * ContentLanguages::current().
 *
 * The site's own addresses (/es/admin, /es/api…) are never in a language:
 * they are left as they are, and answer "not found".
 */
final class LanguagePrefix implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $others = ContentLanguages::others();
        [$code, $rest] = $others === [] ? ['', $request->path] : ContentLanguages::split($request->path, $others);
        if ($code !== '' && in_array(explode('/', ltrim($rest, '/'))[0], Entries::RESERVED_PATHS, true)) {
            $code = '';
            $rest = $request->path;
        }

        return ContentLanguages::within($code, static fn(): Response => $next($code === '' ? $request : $request->withPath($rest)));
    }
}
