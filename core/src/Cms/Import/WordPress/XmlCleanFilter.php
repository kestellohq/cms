<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use php_user_filter;

/**
 * Drops the control characters XML 1.0 forbids (everything below 0x20 except
 * tab, line feed and carriage return) while a file streams through.
 *
 * WordPress writes whatever was pasted into a post, so exports often carry a
 * stray vertical tab or form feed that makes the whole file unreadable. These
 * bytes never occur inside a multi-byte UTF-8 character, so dropping them one
 * byte at a time is safe at any chunk boundary.
 *
 * @internal
 */
final class XmlCleanFilter extends php_user_filter
{
    public const string NAME = 'kestello.xml-clean';

    public static function register(): void
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    public static function clean(string $bytes): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $bytes);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $bucket->data = self::clean($bucket->data);
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
