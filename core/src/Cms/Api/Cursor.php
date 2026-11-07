<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

/**
 * Opaque keyset-pagination cursors ("after id N"), base64url encoded.
 *
 * @api
 */
final class Cursor
{
    public static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode('id:' . $id), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): ?int
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return is_string($raw) && preg_match('/^id:(\d{1,18})$/', $raw, $m) ? (int) $m[1] : null;
    }
}
