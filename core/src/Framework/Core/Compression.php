<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * Gzip for text responses, so shared hosting without server-side compression
 * still sends pages, scripts and styles small.
 *
 * Only responses that hold nothing personal are compressed: ones marked
 * "public" (cached pages, assets) or sent to a request with no cookies and no
 * Authorization header. Compressing a page that mixes a secret (a CSRF token,
 * a session-bound value) with text an attacker can reflect lets the response
 * size leak that secret (BREACH), so signed-in responses go out as they are.
 *
 * A web server that already compresses sees Content-Encoding and leaves the
 * response alone.
 */
final class Compression
{
    private const int MIN_BYTES = 1024;

    private const string TYPES = '#^(text/|application/(json|problem\+json|javascript|xml|rss\+xml|atom\+xml|manifest\+json)|image/svg\+xml)#i';

    public static function apply(Request $request, Response $response): Response
    {
        if (!self::accepts($request) || !self::eligible($request, $response)) {
            return $response;
        }
        $body = gzencode($response->content(), 6);
        if ($body === false) {
            return $response;
        }
        $headers = $response->headers();
        $out = $response->withContent($body)
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Vary', self::vary($headers['Vary'] ?? ''));
        if (isset($headers['Content-Length'])) {
            $out = $out->withHeader('Content-Length', (string) strlen($body));
        }
        if (isset($headers['ETag'])) {
            // a different body is a different representation (RFC 9110 §8.8.3)
            $out = $out->withHeader('ETag', self::etag($headers['ETag']));
        }

        return $out;
    }

    /** Whether If-None-Match names $etag, whether the client saw the plain or the gzip variant. */
    public static function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '' || $etag === '') {
            return false;
        }
        $want = self::bare($etag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || self::bare($candidate) === $want) {
                return true;
            }
        }

        return false;
    }

    private static function accepts(Request $request): bool
    {
        if (!function_exists('gzencode') || filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        foreach (explode(',', strtolower($request->header('Accept-Encoding'))) as $part) {
            $bits = array_map('trim', explode(';', $part));
            if ($bits[0] === 'gzip' || $bits[0] === '*') {
                $q = 1.0;
                foreach (array_slice($bits, 1) as $param) {
                    if (str_starts_with($param, 'q=')) {
                        $q = (float) substr($param, 2);
                    }
                }

                return $q > 0;
            }
        }

        return false;
    }

    private static function eligible(Request $request, Response $response): bool
    {
        $headers = $response->headers();
        $status = $response->statusCode();
        if ($response->bodyStream() !== null || isset($headers['Content-Encoding']) || $status < 200 || $status === 204 || $status === 304
            || strlen($response->content()) < self::MIN_BYTES || preg_match(self::TYPES, $headers['Content-Type'] ?? '') !== 1) {
            return false;
        }
        $public = preg_match('/(^|[\s,])public([\s,]|$)/i', $headers['Cache-Control'] ?? '') === 1;
        $anonymous = $request->cookies === [] && $request->header('Authorization') === '' && $response->cookies() === [];

        return $public || $anonymous;
    }

    private static function vary(string $vary): string
    {
        $parts = array_filter(array_map('trim', explode(',', $vary)), static fn(string $p): bool => $p !== '');
        foreach ($parts as $p) {
            if (strcasecmp($p, 'Accept-Encoding') === 0 || $p === '*') {
                return implode(', ', $parts);
            }
        }
        $parts[] = 'Accept-Encoding';

        return implode(', ', $parts);
    }

    private static function etag(string $etag): string
    {
        return str_ends_with($etag, '"') ? substr($etag, 0, -1) . '-gzip"' : $etag;
    }

    private static function bare(string $etag): string
    {
        $etag = str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;

        return str_ends_with($etag, '-gzip"') ? substr($etag, 0, -6) . '"' : $etag;
    }
}
