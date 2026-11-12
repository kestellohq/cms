<?php

declare(strict_types=1);

namespace Kestello\Cms\Webhooks;

/**
 * The Kestello-Signature header: "t=<unix seconds>,v1=<hex>", where hex is
 * HMAC-SHA256 of "<t>.<raw body>" with the webhook's secret. Right after a
 * secret is rotated there are two v1 values (new and old); one matching is
 * enough.
 *
 *   if (!Signature::verify($_SERVER['HTTP_KESTELLO_SIGNATURE'] ?? '', file_get_contents('php://input'), $secret)) {
 *       http_response_code(400); exit;
 *   }
 *
 * @api
 */
final class Signature
{
    /** Messages older (or newer) than this are refused, so a captured one can't be replayed later. */
    public const int TOLERANCE = 300;

    public static function sign(#[\SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $header, string $body, #[\SensitiveParameter] string $secret, int $tolerance = self::TOLERANCE, ?int $now = null): bool
    {
        $t = null;
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $t = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $candidates[] = $value;
            }
        }
        if ($t === null || $candidates === [] || abs(($now ?? time()) - $t) > $tolerance) {
            return false;
        }
        $expected = self::sign($secret, $t, $body);
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
