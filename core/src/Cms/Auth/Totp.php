<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1, 6 digits, 30 s) —
 * what Google Authenticator, 1Password, Aegis and friends implement.
 *
 * @api
 */
final class Totp
{
    public const int PERIOD = 30;
    public const int DIGITS = 6;
    /** Accept one step either side for clock drift. */
    public const int WINDOW = 1;

    private const string BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new 160-bit secret, base32 encoded. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(#[\SensitiveParameter] string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * The matching time step, or null. Steps at or below $lastUsedStep are
     * refused, so a code can't be replayed.
     */
    public static function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, ?int $lastUsedStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = self::step($time);
        $match = null;
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            // Check every candidate (no early exit) to keep timing flat.
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $match ??= $step;
            }
        }

        return $match;
    }

    /** otpauth:// URI for QR codes. */
    public static function provisioningUri(#[\SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[\s=-]+/', '', $value) ?? '');
        $bits = '';
        foreach (str_split($value) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
