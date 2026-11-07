<?php

declare(strict_types=1);

namespace Kestello\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The current time, controllable in tests:
 *
 *   Clock::freeze('2026-01-01 12:00:00');  Clock::travel(3600);  Clock::unfreeze();
 *
 * Date::now() and everything built on it read from here.
 *
 * @api
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;
    private static int $offset = 0;

    public static function now(?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $timezone ??= new DateTimeZone(date_default_timezone_get());
        $now = self::$frozen ?? new DateTimeImmutable('now', $timezone);
        if (self::$offset !== 0) {
            $now = $now->modify(sprintf('%+d seconds', self::$offset));
        }

        return $now->setTimezone($timezone);
    }

    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    public static function freeze(\DateTimeInterface|string|null $at = null): void
    {
        self::$frozen = match (true) {
            $at instanceof \DateTimeInterface => DateTimeImmutable::createFromInterface($at),
            is_string($at) => new DateTimeImmutable($at),
            default => new DateTimeImmutable('now'),
        };
        self::$offset = 0;
    }

    /** Move the clock by $seconds (frozen or not). */
    public static function travel(int $seconds): void
    {
        self::$offset += $seconds;
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
        self::$offset = 0;
    }

    public static function isFrozen(): bool
    {
        return self::$frozen !== null || self::$offset !== 0;
    }
}
