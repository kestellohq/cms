<?php

declare(strict_types=1);

namespace Kestello\Framework\Http;

/**
 * Which reverse proxies may set X-Forwarded-For.
 *
 * Without configured proxies the client IP is always REMOTE_ADDR, so a
 * client cannot spoof its address (and dodge per-IP rate limits) by sending
 * its own X-Forwarded-For header. Configure with app.trusted_proxies
 * (IPs or CIDR ranges, IPv4 and IPv6).
 */
final class TrustedProxies
{
    /** @var list<string> */
    private static array $proxies = [];

    /** @param list<string> $proxies */
    public static function set(array $proxies): void
    {
        self::$proxies = array_values(array_filter(array_map('strval', $proxies), static fn(string $p): bool => $p !== ''));
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::$proxies;
    }

    /**
     * Resolve the client IP: REMOTE_ADDR, unless it is a trusted proxy, in
     * which case X-Forwarded-For is walked from the right and the first
     * address that is not a trusted proxy wins.
     */
    public static function clientIp(string $remoteAddr, string $forwardedFor): string
    {
        if (self::$proxies === [] || !self::isTrusted($remoteAddr) || $forwardedFor === '') {
            return $remoteAddr;
        }

        $hops = array_reverse(array_map('trim', explode(',', $forwardedFor)));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return $remoteAddr; // malformed chain: don't trust any of it
            }
            if (!self::isTrusted($hop)) {
                return $hop;
            }
        }

        return $remoteAddr;
    }

    public static function isTrusted(string $ip): bool
    {
        foreach (self::$proxies as $proxy) {
            if (self::matches($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
