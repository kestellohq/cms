<?php

declare(strict_types=1);

namespace Kestello\Cms\Storage;

use Kestello\Support\Hash;

/**
 * Expiring links to files on local disks: /system/files?d=&p=&e=&n=&s=
 * The HMAC covers disk, path, expiry and download name, with a subkey of
 * the app key (old links survive a key rotation until they expire).
 */
final class SignedFileUrl
{
    public const string ROUTE = '/system/files';

    /** @param array<string, string> $options download_name, content_type */
    public static function make(string $baseUrl, string $disk, string $path, int $seconds, array $options = [], ?int $now = null): string
    {
        $params = ['d' => $disk, 'p' => $path, 'e' => (string) (($now ?? time()) + max(1, $seconds))];
        if (($options['download_name'] ?? '') !== '') {
            $params['n'] = $options['download_name'];
        }
        $params['s'] = Hash::hmac(self::payload($params), 'storage.url');

        return rtrim($baseUrl, '/') . self::ROUTE . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{disk: string, path: string, name: ?string}|null
     */
    public static function verify(array $query, ?int $now = null): ?array
    {
        foreach (['d', 'p', 'e', 's'] as $k) {
            if (!is_string($query[$k] ?? null) || $query[$k] === '') {
                return null;
            }
        }
        $params = ['d' => $query['d'], 'p' => $query['p'], 'e' => $query['e']];
        if (is_string($query['n'] ?? null) && $query['n'] !== '') {
            $params['n'] = $query['n'];
        }
        if (!ctype_digit($params['e']) || (int) $params['e'] < ($now ?? time())) {
            return null;
        }
        if (!Hash::verifyHmac(self::payload($params), (string) $query['s'], 'storage.url')) {
            return null;
        }

        return ['disk' => $params['d'], 'path' => $params['p'], 'name' => $params['n'] ?? null];
    }

    /** @param array<string, string> $params */
    private static function payload(array $params): string
    {
        return implode("\n", [$params['d'], $params['p'], $params['e'], $params['n'] ?? '']);
    }
}
