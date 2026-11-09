<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

/**
 * The checks that stop a stale or rolled-back copy of keys.json or the index from being taken as
 * current (registry-format.md §4): a serial that never goes down, an expiry, and no date from the
 * future beyond the clock tolerance.
 *
 * @internal
 */
final class Freshness
{
    /**
     * @param array<mixed> $d the payload
     * @return array{serial: int, issued_at: int, expires_at: int}
     * @throws RegistryException
     */
    public static function check(array $d, int $now, int $maxLifetime, ?int $lastSerial, string $what): array
    {
        $serial = $d['serial'] ?? null;
        $issued = Fields::time($d['issued_at'] ?? null);
        $expires = Fields::time($d['expires_at'] ?? null);
        if (!is_int($serial) || $serial < 1 || $issued === null || $expires === null || $expires <= $issued || $expires - $issued > $maxLifetime) {
            throw new RegistryException("The registry's {$what} has no valid serial number or dates.");
        }
        if ($lastSerial !== null && $serial < $lastSerial) {
            throw new RegistryException("The registry's {$what} is older than one this site has already seen (number {$serial}, seen {$lastSerial}). A mirror or cache may be out of date.");
        }
        if ($issued > $now + Fields::CLOCK_TOLERANCE) {
            throw new RegistryException("The registry's {$what} is dated in the future. Check this server's clock.");
        }
        if ($now > $expires + Fields::CLOCK_TOLERANCE) {
            throw new RegistryException("The registry's {$what} has expired. A mirror or cache may be out of date, or the registry hasn't been updated.");
        }

        return ['serial' => $serial, 'issued_at' => $issued, 'expires_at' => $expires];
    }
}
