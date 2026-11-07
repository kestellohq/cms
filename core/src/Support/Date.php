<?php

declare(strict_types=1);

namespace Kestello\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable date-time. Store UTC (toDatabase()), display in the site or
 * user timezone (tz(), formatLocale()).
 *
 *   Date::parse($row['created_at'], 'UTC')->tz('Europe/Belgrade')->formatLocale('medium', 'sr_Latn')
 *   Date::now()->startOfMonth()->addMonths(1)
 *   $post->date->diffForHumans()   // "3 hours ago"
 *
 * @api
 */
final class Date implements JsonSerializable, Stringable
{
    private const array UNITS = [
        'year' => 31_536_000, 'month' => 2_592_000, 'week' => 604_800, 'day' => 86_400,
        'hour' => 3_600, 'minute' => 60, 'second' => 1,
    ];

    private function __construct(private readonly DateTimeImmutable $dt) {}

    // ── Creating ────────────────────────────────────────────────────

    /**
     * A time as APIs give it: ISO 8601 in UTC with its zone ("2026-11-06T19:41:02Z"),
     * or null. A zoneless string (as the database stores times) is read as UTC; a
     * browser would read it as its own local time.
     */
    public static function isoUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $at = $value instanceof DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : new \DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function now(DateTimeZone|string|null $tz = null): self
    {
        return new self(Clock::now(self::zone($tz)));
    }

    public static function today(DateTimeZone|string|null $tz = null): self
    {
        return self::now($tz)->startOfDay();
    }

    /** Parse any strtotime()-style string, timestamp or DateTimeInterface. */
    public static function parse(DateTimeInterface|self|string|int $value, DateTimeZone|string|null $tz = null): self
    {
        if ($value instanceof self) {
            return $tz === null ? $value : $value->tz($tz);
        }
        if ($value instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($value);
            return new self($tz === null ? $dt : $dt->setTimezone(self::zone($tz)));
        }
        if (is_int($value)) {
            return self::fromTimestamp($value, $tz);
        }

        try {
            $relative = preg_match('/^(now|today|tomorrow|yesterday|[+-]|next |last |this )/i', trim($value)) === 1;
            $base = $relative ? Clock::now(self::zone($tz)) : null;
            $dt = $base !== null ? $base->modify($value) : new DateTimeImmutable($value, self::zone($tz));
            if ($dt === false) {
                throw new \Exception('modify failed');
            }
        } catch (\Exception) {
            throw new InvalidArgumentException("Cannot parse date \"{$value}\".");
        }

        return new self($dt);
    }

    /** Like parse(), but null for null/empty/invalid input. */
    public static function tryParse(DateTimeInterface|self|string|int|null $value, DateTimeZone|string|null $tz = null): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return self::parse($value, $tz);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function fromFormat(string $format, string $value, DateTimeZone|string|null $tz = null): self
    {
        $dt = DateTimeImmutable::createFromFormat('!' . ltrim($format, '!'), $value, self::zone($tz));
        if ($dt === false) {
            throw new InvalidArgumentException("\"{$value}\" does not match format \"{$format}\".");
        }

        return new self($dt);
    }

    public static function fromTimestamp(int $timestamp, DateTimeZone|string|null $tz = null): self
    {
        return new self((new DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone($tz)));
    }

    public static function create(int $year, int $month = 1, int $day = 1, int $hour = 0, int $minute = 0, int $second = 0, DateTimeZone|string|null $tz = null): self
    {
        return new self((new DateTimeImmutable('now', self::zone($tz)))->setDate($year, $month, $day)->setTime($hour, $minute, $second));
    }

    /** A database value stored as UTC "Y-m-d H:i:s". */
    public static function fromDatabase(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::parse($value, 'UTC');
    }

    // ── Getters ─────────────────────────────────────────────────────

    public function year(): int
    {
        return (int) $this->dt->format('Y');
    }
    public function month(): int
    {
        return (int) $this->dt->format('n');
    }
    public function day(): int
    {
        return (int) $this->dt->format('j');
    }
    public function hour(): int
    {
        return (int) $this->dt->format('G');
    }
    public function minute(): int
    {
        return (int) $this->dt->format('i');
    }
    public function second(): int
    {
        return (int) $this->dt->format('s');
    }
    /** 1 (Monday) … 7 (Sunday) */
    public function dayOfWeek(): int
    {
        return (int) $this->dt->format('N');
    }
    public function dayOfYear(): int
    {
        return (int) $this->dt->format('z') + 1;
    }
    public function daysInMonth(): int
    {
        return (int) $this->dt->format('t');
    }
    public function timestamp(): int
    {
        return $this->dt->getTimestamp();
    }
    public function timezoneName(): string
    {
        return $this->dt->getTimezone()->getName();
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        return $this->dt;
    }

    // ── Arithmetic ──────────────────────────────────────────────────

    public function addSeconds(int $n): self
    {
        return $this->modify("{$this->sign($n)} seconds");
    }
    public function addMinutes(int $n): self
    {
        return $this->modify("{$this->sign($n)} minutes");
    }
    public function addHours(int $n): self
    {
        return $this->modify("{$this->sign($n)} hours");
    }
    public function addDays(int $n): self
    {
        return $this->modify("{$this->sign($n)} days");
    }
    public function addWeeks(int $n): self
    {
        return $this->modify("{$this->sign($n * 7)} days");
    }
    public function addYears(int $n): self
    {
        return $this->addMonths($n * 12);
    }
    public function subSeconds(int $n): self
    {
        return $this->addSeconds(-$n);
    }
    public function subMinutes(int $n): self
    {
        return $this->addMinutes(-$n);
    }
    public function subHours(int $n): self
    {
        return $this->addHours(-$n);
    }
    public function subDays(int $n): self
    {
        return $this->addDays(-$n);
    }
    public function subWeeks(int $n): self
    {
        return $this->addWeeks(-$n);
    }
    public function subMonths(int $n): self
    {
        return $this->addMonths(-$n);
    }
    public function subYears(int $n): self
    {
        return $this->addYears(-$n);
    }

    /** Calendar months without overflow: Jan 31 + 1 month = Feb 28/29. */
    public function addMonths(int $n): self
    {
        $total = $this->year() * 12 + ($this->month() - 1) + $n;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $first = $this->dt->setDate($year, $month, 1);

        return new self($first->setDate($year, $month, min($this->day(), (int) $first->format('t'))));
    }

    public function add(DateInterval|string $interval): self
    {
        return new self($this->dt->add($interval instanceof DateInterval ? $interval : DateInterval::createFromDateString($interval)));
    }

    public function sub(DateInterval|string $interval): self
    {
        return new self($this->dt->sub($interval instanceof DateInterval ? $interval : DateInterval::createFromDateString($interval)));
    }

    public function modify(string $modifier): self
    {
        $dt = @$this->dt->modify($modifier);
        if ($dt === false) {
            throw new InvalidArgumentException("Invalid date modifier \"{$modifier}\".");
        }

        return new self($dt);
    }

    public function setTime(int $hour, int $minute = 0, int $second = 0): self
    {
        return new self($this->dt->setTime($hour, $minute, $second));
    }

    public function setDate(int $year, int $month, int $day): self
    {
        return new self($this->dt->setDate($year, $month, $day));
    }

    // ── Boundaries ──────────────────────────────────────────────────

    public function startOfDay(): self
    {
        return $this->setTime(0, 0, 0);
    }
    public function endOfDay(): self
    {
        return new self($this->dt->setTime(23, 59, 59, 999999));
    }
    public function startOfMonth(): self
    {
        return $this->setDate($this->year(), $this->month(), 1)->startOfDay();
    }
    public function endOfMonth(): self
    {
        return $this->setDate($this->year(), $this->month(), $this->daysInMonth())->endOfDay();
    }
    public function startOfYear(): self
    {
        return $this->setDate($this->year(), 1, 1)->startOfDay();
    }
    public function endOfYear(): self
    {
        return $this->setDate($this->year(), 12, 31)->endOfDay();
    }

    /** Weeks start on Monday by default (ISO 8601). */
    public function startOfWeek(int $weekStartsOn = 1): self
    {
        $diff = ($this->dayOfWeek() - $weekStartsOn + 7) % 7;

        return $this->subDays($diff)->startOfDay();
    }

    public function endOfWeek(int $weekStartsOn = 1): self
    {
        return $this->startOfWeek($weekStartsOn)->addDays(6)->endOfDay();
    }

    // ── Comparison ──────────────────────────────────────────────────

    public function isBefore(DateTimeInterface|self|string $other): bool
    {
        return $this->dt < self::parse($other)->dt;
    }
    public function isAfter(DateTimeInterface|self|string $other): bool
    {
        return $this->dt > self::parse($other)->dt;
    }
    public function equalTo(DateTimeInterface|self|string $other): bool
    {
        return $this->dt == self::parse($other)->dt;
    }

    public function between(DateTimeInterface|self|string $start, DateTimeInterface|self|string $end, bool $inclusive = true): bool
    {
        $s = self::parse($start)->dt;
        $e = self::parse($end)->dt;

        return $inclusive ? $this->dt >= $s && $this->dt <= $e : $this->dt > $s && $this->dt < $e;
    }

    public function isPast(): bool
    {
        return $this->dt < Clock::now();
    }
    public function isFuture(): bool
    {
        return $this->dt > Clock::now();
    }
    public function isToday(): bool
    {
        return $this->dt->format('Y-m-d') === Clock::now($this->dt->getTimezone())->format('Y-m-d');
    }
    public function isWeekend(): bool
    {
        return $this->dayOfWeek() >= 6;
    }
    public function isSameDay(DateTimeInterface|self|string $other): bool
    {
        return $this->dt->format('Y-m-d') === self::parse($other)->tz($this->dt->getTimezone())->format('Y-m-d');
    }

    /** Signed difference ($other - this); positive when $other is later. */
    public function diffInSeconds(DateTimeInterface|self|string|null $other = null): int
    {
        return ($other === null ? Clock::timestamp() : self::parse($other)->timestamp()) - $this->timestamp();
    }

    public function diffInMinutes(DateTimeInterface|self|string|null $other = null): int
    {
        return intdiv($this->diffInSeconds($other), 60);
    }
    public function diffInHours(DateTimeInterface|self|string|null $other = null): int
    {
        return intdiv($this->diffInSeconds($other), 3600);
    }

    /** Calendar days (DST-safe). */
    public function diffInDays(DateTimeInterface|self|string|null $other = null): int
    {
        $other = $other === null ? self::now($this->dt->getTimezone()) : self::parse($other);

        return (int) $this->dt->diff($other->dt)->format('%r%a');
    }

    /**
     * "3 minutes ago", "in 2 days", "just now". Other languages come from
     * the Translator (core domain): the keys are English ICU plural
     * messages such as "{count, plural, one {# minute ago} other {# minutes ago}}".
     */
    public function diffForHumans(DateTimeInterface|self|string|null $other = null, bool $short = false): string
    {
        $seconds = $this->diffInSeconds($other);
        $past = $seconds >= 0;
        $abs = abs($seconds);

        $translate = !$short && !str_starts_with(Translator::locale(), 'en') && class_exists(\MessageFormatter::class);
        if ($abs < 10) {
            return $translate ? Translator::get('just now') : 'just now';
        }

        foreach (self::UNITS as $unit => $size) {
            if ($abs >= $size) {
                $n = (int) floor($abs / $size);
                if ($translate) {
                    $pattern = $past ? ($other === null ? '# %s ago' : '# %s before') : ($other === null ? 'in # %s' : '# %s after');
                    $key = '{count, plural, one {' . sprintf($pattern, $unit) . '} other {' . sprintf($pattern, $unit . 's') . '}}';

                    return Translator::choice($key, $n);
                }
                $label = $short ? ['year' => 'y', 'month' => 'mo', 'week' => 'w', 'day' => 'd', 'hour' => 'h', 'minute' => 'm', 'second' => 's'][$unit] : ' ' . $unit . ($n === 1 ? '' : 's');
                $text = $n . $label;

                return $past ? ($other === null ? "{$text} ago" : "{$text} before") : ($other === null ? "in {$text}" : "{$text} after");
            }
        }

        return 'just now';
    }

    // ── Timezones and output ────────────────────────────────────────

    public function tz(DateTimeZone|string $tz): self
    {
        return new self($this->dt->setTimezone(self::zone($tz)));
    }

    public function utc(): self
    {
        return $this->tz('UTC');
    }

    public function format(string $format): string
    {
        return $this->dt->format($format);
    }

    /**
     * Localized output through intl. $style: "short", "medium", "long",
     * "full", or an ICU pattern like "d. MMMM y.".
     */
    public function formatLocale(string $style = 'medium', ?string $locale = null, string $timeStyle = 'none'): string
    {
        $styles = ['none' => -1, 'full' => 0, 'long' => 1, 'medium' => 2, 'short' => 3];
        $locale ??= \Locale::getDefault() ?: 'en';

        if (!class_exists(\IntlDateFormatter::class)) {
            return $this->dt->format(isset($styles[$style]) ? 'Y-m-d' . ($timeStyle !== 'none' ? ' H:i' : '') : 'Y-m-d H:i');
        }

        $formatter = isset($styles[$style])
            ? new \IntlDateFormatter($locale, $styles[$style], $styles[$timeStyle] ?? -1, $this->dt->getTimezone())
            : new \IntlDateFormatter($locale, -1, -1, $this->dt->getTimezone(), null, $style);

        $out = $formatter->format($this->dt);

        return $out === false ? $this->dt->format('Y-m-d H:i') : $out;
    }

    public function toDateString(): string
    {
        return $this->dt->format('Y-m-d');
    }
    public function toTimeString(): string
    {
        return $this->dt->format('H:i:s');
    }
    public function toDateTimeString(): string
    {
        return $this->dt->format('Y-m-d H:i:s');
    }
    public function toIso8601(): string
    {
        return $this->dt->format(DATE_ATOM);
    }

    /** UTC "Y-m-d H:i:s" for database columns. */
    public function toDatabase(): string
    {
        return $this->dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function jsonSerialize(): string
    {
        return $this->toIso8601();
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    // ── Interop ─────────────────────────────────────────────────────

    public function diff(DateTimeInterface|self $target, bool $absolute = false): DateInterval
    {
        return $this->dt->diff($target instanceof self ? $target->dt : $target, $absolute);
    }

    public function getTimestamp(): int
    {
        return $this->dt->getTimestamp();
    }
    public function getTimezone(): DateTimeZone
    {
        return $this->dt->getTimezone();
    }

    private function sign(int $n): string
    {
        return ($n >= 0 ? '+' : '') . $n;
    }

    private static function zone(DateTimeZone|string|null $tz): DateTimeZone
    {
        if ($tz instanceof DateTimeZone) {
            return $tz;
        }

        try {
            return new DateTimeZone($tz ?? date_default_timezone_get());
        } catch (\Exception) {
            throw new InvalidArgumentException("Unknown timezone \"{$tz}\".");
        }
    }
}
