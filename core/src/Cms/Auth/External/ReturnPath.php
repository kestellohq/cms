<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

/** "Where to go after sign-in": local paths only, never another site. @internal */
final class ReturnPath
{
    public static function sanitize(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || strlen($path) > 2000) {
            return null;
        }
        // "/x" only: not "//evil", "/\evil", "http:", control characters or backslashes.
        if (preg_match('#^/(?![/\\\\])[^\\\\\x00-\x1f\x7f]*$#', $path) !== 1) {
            return null;
        }

        return $path;
    }
}
