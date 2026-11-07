<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Kestello\Contracts\Storage\StorageException;

/** Path rules shared by every driver. @api */
final class StoragePath
{
    /**
     * A file path: "a/b.txt". Refuses empty, absolute, "..", ".", "//",
     * backslashes, control characters and over-long paths.
     */
    public static function file(string $path): string
    {
        $path = self::check($path);
        if ($path === '' || str_ends_with($path, '/')) {
            throw new StorageException('A file path is required.');
        }

        return $path;
    }

    /** A directory prefix: "" (root) or "a/b/" (always with a trailing slash). */
    public static function directory(string $path): string
    {
        $path = self::check(rtrim($path, '/'));

        return $path === '' ? '' : $path . '/';
    }

    /** "2026/11/a b.jpg" → "2026/11/a%20b.jpg" */
    public static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function check(string $path): string
    {
        if (strlen($path) > 1024) {
            throw new StorageException('Path too long.');
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1) {
            throw new StorageException('Paths may not contain control characters or backslashes.');
        }
        if (str_starts_with($path, '/')) {
            throw new StorageException('Paths are relative to the disk.');
        }
        if ($path === '') {
            return '';
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new StorageException("Invalid path \"{$path}\".");
            }
        }

        return $path;
    }
}
