<?php

declare(strict_types=1);

namespace Kestello\Support;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Standard 5-field cron expressions.
 *
 *   minute hour day-of-month month day-of-week
 *   "*\/15 * * * *"   "10 3 * * *"   "0 9 * * mon-fri"   "0 0 1,15 * *"   "@daily"
 *
 * Fields take *, numbers, ranges (1-5), steps (*\/10, 0-30/5, 5/15), lists
 * (1,3,5) and names (jan-dec, sun-sat; 7 is Sunday too). As in Vixie cron,
 * when both day-of-month and day-of-week are restricted, either may match.
 *
 * @api
 */
final readonly class CronExpression
{
    private const array ALIASES = [
        '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *',
    ];
    private const array MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    private const array DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** @var array<int, true> */
    private array $minutes;
    /** @var array<int, true> */
    private array $hours;
    /** @var array<int, true> */
    private array $days;
    /** @var array<int, true> */
    private array $months;
    /** @var array<int, true> */
    private array $weekdays;
    private bool $dayRestricted;
    private bool $weekdayRestricted;
    public string $expression;

    public function __construct(string $expression)
    {
        $expression = trim(strtolower($expression));
        $expression = self::ALIASES[$expression] ?? $expression;
        $fields = preg_split('/\s+/', $expression) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException("Cron expression \"{$expression}\" must have 5 fields.");
        }

        $this->expression = implode(' ', $fields);
        $this->minutes = self::parse($fields[0], 0, 59);
        $this->hours = self::parse($fields[1], 0, 23);
        $this->days = self::parse($fields[2], 1, 31);
        $this->months = self::parse($fields[3], 1, 12, self::MONTHS);
        $weekdays = self::parse($fields[4], 0, 7, self::DAYS);
        if (isset($weekdays[7])) {
            $weekdays[0] = true;
            unset($weekdays[7]);
        }
        $this->weekdays = $weekdays;
        $this->dayRestricted = $fields[2] !== '*' && !str_starts_with($fields[2], '*/');
        $this->weekdayRestricted = $fields[4] !== '*' && !str_starts_with($fields[4], '*/');
    }

    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function isDue(DateTimeInterface $at): bool
    {
        return isset($this->minutes[(int) $at->format('i')], $this->hours[(int) $at->format('G')])
            && $this->matchesDay(DateTimeImmutable::createFromInterface($at));
    }

    /** The latest matching minute at or before $at. */
    public function previousRun(DateTimeInterface $at): DateTimeImmutable
    {
        return $this->search(DateTimeImmutable::createFromInterface($at), -1);
    }

    /** The earliest matching minute strictly after $at. */
    public function nextRun(DateTimeInterface $at): DateTimeImmutable
    {
        return $this->search(DateTimeImmutable::createFromInterface($at)->modify('+1 minute'), 1);
    }

    private function search(DateTimeImmutable $at, int $direction): DateTimeImmutable
    {
        $at = $at->setTime((int) $at->format('G'), (int) $at->format('i'));
        $hours = array_keys($this->hours);
        $minutes = array_keys($this->minutes);
        $direction < 0 ? rsort($hours) : sort($hours);
        $direction < 0 ? rsort($minutes) : sort($minutes);

        $day = $at;
        for ($i = 0; $i < 366 * 8; $i++) {
            if ($this->matchesDay($day)) {
                $sameDay = $i === 0;
                foreach ($hours as $h) {
                    if ($sameDay && ($direction < 0 ? $h > (int) $at->format('G') : $h < (int) $at->format('G'))) {
                        continue;
                    }
                    foreach ($minutes as $m) {
                        if ($sameDay && $h === (int) $at->format('G') && ($direction < 0 ? $m > (int) $at->format('i') : $m < (int) $at->format('i'))) {
                            continue;
                        }
                        $candidate = $day->setTime($h, $m);
                        // 02:30 on the night clocks skip 02:00-03:00 becomes 03:30: never a "previous" run in the future
                        if ($direction < 0 && $candidate > $at) {
                            continue;
                        }

                        return $candidate;
                    }
                }
            }
            $day = $day->modify($direction < 0 ? '-1 day' : '+1 day');
        }

        throw new InvalidArgumentException("Cron expression \"{$this->expression}\" never matches.");
    }

    private function matchesDay(DateTimeImmutable $day): bool
    {
        if (!isset($this->months[(int) $day->format('n')])) {
            return false;
        }
        $dom = isset($this->days[(int) $day->format('j')]);
        $dow = isset($this->weekdays[(int) $day->format('w')]);

        return $this->dayRestricted && $this->weekdayRestricted ? ($dom || $dow) : ($dom && $dow);
    }

    /**
     * @param array<string, int> $names
     * @return array<int, true>
     */
    private static function parse(string $field, int $min, int $max, array $names = []): array
    {
        $values = [];
        foreach (explode(',', $field) as $part) {
            if (preg_match('#^(\*|[a-z0-9]+(?:-[a-z0-9]+)?)(?:/(\d+))?$#', $part, $m) !== 1) {
                throw new InvalidArgumentException("Invalid cron field \"{$field}\".");
            }
            $step = isset($m[2]) ? (int) $m[2] : 1;
            if ($step < 1) {
                throw new InvalidArgumentException("Invalid step in \"{$field}\".");
            }
            if ($m[1] === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($m[1], '-')) {
                [$a, $b] = explode('-', $m[1]);
                [$from, $to] = [self::value($a, $names), self::value($b, $names)];
            } else {
                $from = self::value($m[1], $names);
                $to = isset($m[2]) ? $max : $from; // "5/15" = from 5 every 15
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException("Cron field \"{$field}\" is out of range {$min}-{$max}.");
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $values[$v] = true;
            }
        }

        return $values;
    }

    /** @param array<string, int> $names */
    private static function value(string $token, array $names): int
    {
        if (isset($names[$token])) {
            return $names[$token];
        }
        if (!ctype_digit($token)) {
            throw new InvalidArgumentException("Unknown cron value \"{$token}\".");
        }

        return (int) $token;
    }
}
