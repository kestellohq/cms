<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Cms;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Session\Session;

/** Remembers the admin page a signed-out visitor asked for, to return there after sign-in. @internal */
final class IntendedUrl
{
    private const string KEY = '_intended';

    public static function remember(Request $request): void
    {
        if ($request->method === 'GET' && self::safe($request->uri)) {
            self::session()?->set(self::KEY, $request->uri);
        }
    }

    public static function pull(string $default): string
    {
        $session = self::session();
        $url = $session?->get(self::KEY);
        $session?->remove(self::KEY);

        return is_string($url) && self::safe($url) ? $url : $default;
    }

    /** Only admin paths on this site ("/admin…", no scheme, no "//", no backslashes). */
    public static function safe(string $url): bool
    {
        return preg_match('#^/admin(?:[/?][^\\\\\s]*)?$#', $url) === 1 && !str_contains($url, '//') && strlen($url) <= 2000;
    }

    private static function session(): ?Session
    {
        $c = Cms::instance()?->container;

        return $c !== null && $c->has(Session::class) ? $c->make(Session::class) : null;
    }
}
