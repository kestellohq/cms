<?php

declare(strict_types=1);

namespace Kestello\Support\Zip;

/**
 * Zip archives in pure PHP (needs only zlib, no ext-zip).
 *
 *   Zip::create($path)->addDirectory($dir, 'my-plugin/')->addString('README.txt', $text)->close();
 *   $zip = Zip::open($path);
 *   foreach ($zip->entries() as $entry) { ... }
 *   $zip->extractTo($dir, maxBytes: 100 << 20);
 *
 * Extraction refuses absolute paths, "..", backslashes, drive letters,
 * symlinks and encrypted entries; it enforces entry, size and compression
 * ratio limits on the real inflated bytes (zip bombs), and checks CRCs.
 *
 * @api
 */
final class Zip
{
    /**
     * Archives switch to ZIP64 by themselves where they need it (over 4 GB, or more
     * than 65,534 entries). $zip64 lays out every entry that way: for tests and tools
     * that expect it. With $hash (an algorithm such as "sha256"), the writer hashes
     * each entry as it reads it: ZipWriter::lastHash().
     */
    public static function create(string $path, bool $zip64 = false, ?string $hash = null): ZipWriter
    {
        return new ZipWriter($path, $zip64, $hash);
    }

    /** The most entries Kestello reads back from archives it wrote, such as backups. */
    public const int MAX_ENTRIES = 1_000_000;

    /** $maxEntries guards against hostile archives; raise it to MAX_ENTRIES for archives you wrote. */
    public static function open(string $path, int $maxEntries = 20_000): ZipReader
    {
        return new ZipReader($path, $maxEntries);
    }

    /** "a/b.txt" for a safe entry name; null for anything that could escape the target. */
    public static function safeName(string $name): ?string
    {
        if ($name === '' || strlen($name) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\:]/', $name) === 1 || str_starts_with($name, '/')) {
            return null;
        }
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }

        return $name;
    }
}
