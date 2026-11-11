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
 * they are left as they are, and answer "not found". Search, feeds and
 * author pages are public pages, so they are.
 */
final class LanguagePrefix implements MiddlewareInterface
{
    /** Reserved addresses that are public pages, so they exist in each language: /es/search, /es/feed, /es/author/ana. */
    public const array IN_EVERY_LANGUAGE = ['search', 'feed', 'author'];

    public function handle(Request $request, Closure $next): Response
    {
        [$code, $rest] = self::detect($request->path);

        return self::run($code, $rest, static fn(): Response => $next($code === '' ? $request : $request->withPath($rest)));
    }

    /**
     * The language of an address and the rest of it: "/es/about" → ["es",
     * "/about"]; the site's own addresses and paths without a known code →
     * ["", $path].
     *
     * @return array{0: string, 1: string}
     */
    public static function detect(string $path): array
    {
        $others = ContentLanguages::others();
        [$code, $rest] = $others === [] ? ['', $path] : ContentLanguages::split($path, $others);
        $first = explode('/', ltrim($rest, '/'))[0];
        if ($code !== '' && in_array($first, Entries::RESERVED_PATHS, true) && !in_array($first, self::IN_EVERY_LANGUAGE, true)) {
            return ['', $path];
        }

        return [$code, $rest];
    }

    /**
     * Run $work as a page in $code at $rest: the language for content and
     * interface text, and the page's addresses in other languages.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(string $code, string $rest, callable $work): mixed
    {
        return ContentLanguages::within($code, static fn() => PageLanguages::during($rest, $work));
    }
}
