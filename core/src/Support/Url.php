<?php

declare(strict_types=1);

namespace Kestello\Support;

use DateTimeInterface;

/**
 * URL parsing and building, redirect safety, and signed URLs.
 *
 *   Url::withQuery('/search?q=a', ['page' => 2])          // "/search?q=a&page=2"
 *   Url::isSafeRedirect($request->input('next'), 'example.com')
 *   $link = Url::signed('https://example.com/unsubscribe?u=5', expires: '+7 days');
 *   Url::hasValidSignature($request->fullUrl())
 *
 * @api
 */
final class Url
{
    /** @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} */
    public static function parse(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException("Malformed URL \"{$url}\".");
        }

        return $parts;
    }

    /** @param array<string, mixed> $parts as returned by parse() */
    public static function build(array $parts): string
    {
        $url = '';
        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . ':';
        }
        if (isset($parts['host'])) {
            $url .= '//';
            if (isset($parts['user'])) {
                $url .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
            }
            $url .= $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        $url .= $parts['path'] ?? '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /** @return array<string, mixed> */
    public static function query(string $url): array
    {
        parse_str(self::parse($url)['query'] ?? '', $query);

        return $query;
    }

    /** Merge parameters into the query string (null removes a parameter). */
    public static function withQuery(string $url, array $params): string
    {
        $parts = self::parse($url);
        parse_str($parts['query'] ?? '', $query);
        foreach ($params as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        $parts['query'] = Arr::query($query);

        return self::build($parts);
    }

    /** Remove the given parameters, or the whole query string. @param list<string>|null $keys */
    public static function withoutQuery(string $url, ?array $keys = null): string
    {
        if ($keys === null) {
            $parts = self::parse($url);
            unset($parts['query']);

            return self::build($parts);
        }

        return self::withQuery($url, array_fill_keys($keys, null));
    }

    public static function isSameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (!is_array($pa) || !is_array($pb) || !isset($pa['host'], $pb['host'])) {
            return false;
        }
        $port = static fn(array $p) => $p['port'] ?? (strtolower($p['scheme'] ?? '') === 'https' ? 443 : 80);

        return strtolower($pa['scheme'] ?? '') === strtolower($pb['scheme'] ?? '')
            && strtolower($pa['host']) === strtolower($pb['host'])
            && $port($pa) === $port($pb);
    }

    /**
     * Whether $target is safe to redirect to: a local path ("/account"), or
     * an http(s) URL on one of $allowedHosts. Blocks "//evil.com", "/\evil.com",
     * "javascript:", control characters and userinfo tricks.
     *
     * @param string|list<string> $allowedHosts
     */
    public static function isSafeRedirect(?string $target, string|array $allowedHosts = []): bool
    {
        if ($target === null || $target === '' || strlen($target) > 2048) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1 || preg_match('/\s/', $target) === 1) {
            return false;
        }

        if ($target[0] === '/') {
            return !str_starts_with($target, '//');
        }

        $parts = parse_url($target);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !isset($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(strtolower($parts['host']), array_map('strtolower', (array) $allowedHosts), true);
    }

    // ── Signed URLs ─────────────────────────────────────────────────

    /**
     * Add an expiry and an HMAC signature (app key) to a URL. Any change to
     * the path or query invalidates it.
     */
    public static function signed(string $url, DateTimeInterface|Date|string|int|null $expires = null, string $purpose = 'default'): string
    {
        $url = self::withoutQuery($url, ['expires', 'signature']);
        if ($expires !== null) {
            $timestamp = match (true) {
                is_int($expires) => Clock::timestamp() + $expires,
                is_string($expires) => Date::parse($expires)->timestamp(),
                default => $expires->getTimestamp(),
            };
            $url = self::withQuery($url, ['expires' => $timestamp]);
        }

        return self::withQuery($url, ['signature' => self::signature($url, AppKey::derive('url.' . $purpose))]);
    }

    public static function hasValidSignature(string $url, string $purpose = 'default'): bool
    {
        $query = self::query($url);
        $signature = $query['signature'] ?? null;
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        if (isset($query['expires']) && (!ctype_digit((string) $query['expires']) || (int) $query['expires'] < Clock::timestamp())) {
            return false;
        }

        $unsigned = self::withoutQuery($url, ['signature']);
        foreach ([AppKey::derive('url.' . $purpose), ...AppKey::derivePrevious('url.' . $purpose)] as $key) {
            if (hash_equals(self::signature($unsigned, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Signature over path + query only, so it survives scheme/host changes behind proxies. */
    private static function signature(string $url, string $key): string
    {
        $parts = self::parse($url);
        parse_str($parts['query'] ?? '', $query);
        ksort($query);

        return hash_hmac('sha256', ($parts['path'] ?? '/') . '?' . Arr::query($query), $key);
    }
}
