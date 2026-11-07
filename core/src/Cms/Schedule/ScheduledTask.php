<?php

declare(strict_types=1);

namespace Kestello\Cms\Schedule;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kestello\Support\CronExpression;

/**
 * One scheduled task, configured fluently:
 *
 *   $cms->schedule->call(fn() => ..., name: 'acme.refresh')->everyFifteenMinutes()->between('08:00', '18:00');
 *   $cms->schedule->job(new PruneThings())->dailyAt('03:10')->timezone('Europe/Belgrade');
 *
 * @api
 */
final class ScheduledTask
{
    private string $cron = '* * * * *';
    private ?string $timezone = null;
    /** @var list<Closure(DateTimeImmutable): bool> */
    private array $filters = [];
    private int $overlapMinutes = 0;
    private string $description = '';

    /**
     * @param 'call'|'job'|'command' $type
     * @param Closure(): mixed|\Kestello\Contracts\Queue\Job|string $target
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly object|string $target,
        public readonly string $owner,
    ) {}

    // ── Frequencies ─────────────────────────────────────────────────

    public function cron(string $expression): self
    {
        if (!CronExpression::isValid($expression)) {
            throw new InvalidArgumentException("Task [{$this->name}]: invalid cron expression \"{$expression}\".");
        }
        $this->cron = (new CronExpression($expression))->expression;

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }
    public function everyTwoMinutes(): self
    {
        return $this->cron('*/2 * * * *');
    }
    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }
    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }
    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }
    public function everyThirtyMinutes(): self
    {
        return $this->cron('0,30 * * * *');
    }
    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }
    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }
    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }
    public function dailyAt(string $time): self
    {
        return $this->daily()->at($time);
    }
    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }
    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }
    public function yearly(): self
    {
        return $this->cron('0 0 1 1 *');
    }

    /** @param int|string $day 0-6 or sun…sat */
    public function weeklyOn(int|string $day, string $time = '00:00'): self
    {
        return $this->cron("0 0 * * {$day}")->at($time);
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        return $this->cron("0 0 {$day} * *")->at($time);
    }

    /** Set the hour and minute ("03:10"). */
    public function at(string $time): self
    {
        [$h, $i] = explode(':', $this->hhmm($time));
        $fields = explode(' ', $this->cron);
        [$fields[0], $fields[1]] = [(string) (int) $i, (string) (int) $h];

        return $this->cron(implode(' ', $fields));
    }

    public function weekdays(): self
    {
        return $this->days('1-5');
    }
    public function weekends(): self
    {
        return $this->days('0,6');
    }

    /** Restrict the day-of-week field ("1-5", "mon,wed"). */
    public function days(string $days): self
    {
        $fields = explode(' ', $this->cron);
        $fields[4] = $days;

        return $this->cron(implode(' ', $fields));
    }

    public function timezone(string $timezone): self
    {
        new DateTimeZone($timezone); // throws on unknown zones
        $this->timezone = $timezone;

        return $this;
    }

    // ── Conditions ──────────────────────────────────────────────────

    /** Only between two times of day (in the task's timezone); "22:00"–"06:00" wraps midnight. */
    public function between(string $start, string $end): self
    {
        [$start, $end] = [$this->hhmm($start), $this->hhmm($end)];
        $this->filters[] = static function (DateTimeImmutable $now) use ($start, $end): bool {
            $t = $now->format('H:i');
            return $start <= $end ? ($t >= $start && $t <= $end) : ($t >= $start || $t <= $end);
        };

        return $this;
    }

    /** "8:00" → "08:00"; anything else that is not a time of day is refused. */
    private function hhmm(string $time): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            throw new InvalidArgumentException("Task [{$this->name}]: invalid time \"{$time}\".");
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /** @param Closure(): bool $condition */
    public function when(Closure $condition): self
    {
        $this->filters[] = static fn() => (bool) $condition();

        return $this;
    }

    /** Skip a run while the previous one is still going (lock expires after $minutes). */
    public function withoutOverlapping(int $minutes = 1440): self
    {
        $this->overlapMinutes = max(1, $minutes);

        return $this;
    }

    /**
     * Run on one server only. Every task already does: each due time is
     * claimed in the database, so this is kept for readability.
     */
    public function onOneServer(): self
    {
        return $this;
    }

    public function description(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    // ── Reading ─────────────────────────────────────────────────────

    public function expression(): string
    {
        return $this->cron;
    }
    public function overlapMinutes(): int
    {
        return $this->overlapMinutes;
    }
    public function getDescription(): string
    {
        return $this->description;
    }

    public function zone(): DateTimeZone
    {
        if ($this->timezone !== null) {
            return new DateTimeZone($this->timezone);
        }
        // "02:30" means 02:30 on the site's clock (Settings → General → Timezone), not the server's
        if (\Kestello\Cms\Cms::instance() !== null) {
            try {
                return \Kestello\Cms\Content\Permalinks::timezone();
            } catch (\Throwable) {
            }
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    /** The latest due time at or before $now. */
    public function previousDue(int $now): int
    {
        return (new CronExpression($this->cron))->previousRun((new DateTimeImmutable('@' . $now))->setTimezone($this->zone()))->getTimestamp();
    }

    public function nextDue(int $now): int
    {
        return (new CronExpression($this->cron))->nextRun((new DateTimeImmutable('@' . $now))->setTimezone($this->zone()))->getTimestamp();
    }

    public function passesFilters(int $now): bool
    {
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($this->zone());
        foreach ($this->filters as $filter) {
            if (!$filter($local)) {
                return false;
            }
        }

        return true;
    }
}
