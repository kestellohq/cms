<?php

declare(strict_types=1);

namespace Kestello\Tests\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Schedule\Schedule;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\CronExpression;

final class PingJob implements Job
{
    public function handle(): void {}
}

final class EchoCommand extends Command
{
    protected string $name = 'acme:echo';
    public function handle(array $arguments, array $options): int
    {
        $this->output->success('args=' . implode(',', $arguments) . ' loud=' . ($options['loud'] ?? '-'));
        return isset($options['fail']) ? 2 : 0;
    }
}

final class ScheduleTest extends TestCase
{
    private Schedule $schedule;
    private ArrayQueue $queue;
    private ArrayStore $cache;
    private ScheduleRunner $runner;
    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        $db = Connection::sqliteMemory();
        DatabaseManager::setConnection($db);
        (require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_04_000007_create_scheduled_tasks_table.php')->up();

        $this->schedule = new Schedule();
        $this->queue = new ArrayQueue();
        $jobs = new JobQueue();
        $jobs->setBackend(fn() => $this->queue);
        $jobs->job(PingJob::class);
        $this->cache = new ArrayStore();
        $this->runner = new ScheduleRunner($this->schedule, $jobs, $this->cache, static fn(string $n) => $n === 'acme:echo' ? new EchoCommand() : null);
    }

    protected function tearDown(): void
    {
        DatabaseManager::reset();
        ExecutionContext::reset();
    }

    private static function at(string $time, string $tz = 'UTC'): int
    {
        return (new DateTimeImmutable($time, new DateTimeZone($tz)))->getTimestamp();
    }

    private function track(string $name): \Closure
    {
        return function () use ($name): string {
            $this->ran[] = $name;
            return "ran {$name}";
        };
    }

    public function testCronExpressions(): void
    {
        $at = new DateTimeImmutable('2026-11-07 12:00:30'); // a Saturday
        $cases = [
            ['* * * * *', '2026-11-07 12:00', '2026-11-07 12:01'],
            ['*/15 * * * *', '2026-11-07 12:00', '2026-11-07 12:15'],
            ['10 3 * * *', '2026-11-07 03:10', '2026-11-08 03:10'],
            ['0 9 * * mon-fri', '2026-11-06 09:00', '2026-11-09 09:00'],
            ['0 0 1,15 * *', '2026-11-01 00:00', '2026-11-15 00:00'],
            ['0 0 29 2 *', '2024-02-29 00:00', '2028-02-29 00:00'],
            ['@monthly', '2026-11-01 00:00', '2026-12-01 00:00'],
            ['30 12 * * 7', '2026-11-01 12:30', '2026-11-08 12:30'], // 7 = Sunday
            ['0 0 13 * fri', '2026-11-06 00:00', '2026-11-13 00:00'], // DOM or DOW (Vixie)
            ['5/20 * * * *', '2026-11-07 11:45', '2026-11-07 12:05'],
            ['0 0 * jan,jul *', '2026-07-31 00:00', '2027-01-01 00:00'],
        ];
        foreach ($cases as [$expr, $prev, $next]) {
            $c = new CronExpression($expr);
            $this->assertSame($prev, $c->previousRun($at)->format('Y-m-d H:i'), "{$expr} previous");
            $this->assertSame($next, $c->nextRun($at)->format('Y-m-d H:i'), "{$expr} next");
        }
        $this->assertTrue((new CronExpression('0 12 * * sat'))->isDue(new DateTimeImmutable('2026-11-07 12:00:59')));
        $this->assertFalse((new CronExpression('0 12 * * sun'))->isDue(new DateTimeImmutable('2026-11-07 12:00')));
        foreach (['* * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '5-1 * * * *', '*/0 * * * *', 'x * * * *', '0 0 31 2 *'] as $bad) {
            $valid = CronExpression::isValid($bad);
            if ($valid) { // syntactically fine but never matches
                $this->assertThrows(\InvalidArgumentException::class, fn() => (new CronExpression($bad))->nextRun(new DateTimeImmutable()), $bad);
            } else {
                $this->assertFalse($valid, $bad);
            }
        }
    }

    public function testFluentFrequencies(): void
    {
        $s = $this->schedule;
        $this->assertSame('10 3 * * *', $s->call(fn() => null, 'a')->dailyAt('03:10')->expression());
        $this->assertSame('0 9 * * 1-5', $s->call(fn() => null, 'b')->dailyAt('09:00')->weekdays()->expression());
        $this->assertSame('30 7 * * mon', $s->call(fn() => null, 'c')->weeklyOn('mon', '07:30')->expression());
        $this->assertSame('0 6 15 * *', $s->call(fn() => null, 'd')->monthlyOn(15, '06:00')->expression());
        $this->assertSame('*/5 * * * *', $s->call(fn() => null, 'e')->everyFiveMinutes()->expression());
        $this->assertThrows(\InvalidArgumentException::class, fn() => $s->call(fn() => null, 'f')->at('25:00'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $s->call(fn() => null, 'g')->cron('bad'));
        $this->assertThrows(\Exception::class, fn() => $s->call(fn() => null, 'h')->timezone('Mars/Base'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $s->call(fn() => null, 'a'), 'duplicate');
        $this->assertSame('ping-job', $s->job(new PingJob())->name);
    }

    public function testPluginTaskNamesAreNamespaced(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $this->schedule->call(fn() => null, 'cleanup')));
        $task = ExecutionContext::run('plugin:acme', fn() => $this->schedule->call(fn() => null, 'acme.cleanup'));
        $this->assertSame('plugin:acme', $task->owner);
        $this->assertSame('acme.ping-job', ExecutionContext::run('plugin:acme', fn() => $this->schedule->job(new PingJob()))->name);
    }

    public function testEachDueTimeRunsExactlyOnceAndNewTasksWait(): void
    {
        $this->schedule->call($this->track('hourly'), 'hourly')->hourly();
        $this->schedule->call($this->track('minutely'), 'minutely')->everyMinute();

        $this->assertSame([], $this->runner->run(self::at('2026-11-07 10:20:30')), 'first sight: nothing runs immediately');
        $this->assertSame(['minutely' => 'ok'], $this->runner->run(self::at('2026-11-07 10:21:05')));
        $this->assertSame([], $this->runner->run(self::at('2026-11-07 10:21:40')), 'same minute: not again (cron + web runner overlap)');
        $this->assertSame(['hourly' => 'ok', 'minutely' => 'ok'], $this->runner->run(self::at('2026-11-07 11:00:10')));

        // Down for hours: one catch-up run, not one per missed slot.
        $this->ran = [];
        $this->runner->run(self::at('2026-11-07 15:42:00'));
        $this->assertSame(['hourly', 'minutely'], $this->ran);
    }

    public function testDailyTaskInItsTimezone(): void
    {
        $this->schedule->call($this->track('report'), 'report')->dailyAt('03:10')->timezone('Europe/Belgrade');
        $this->runner->run(self::at('2026-11-07 00:00', 'Europe/Belgrade'));
        $this->assertSame([], $this->runner->run(self::at('2026-11-07 03:09', 'Europe/Belgrade')));
        $this->assertSame(['report' => 'ok'], $this->runner->run(self::at('2026-11-07 03:10:20', 'Europe/Belgrade')));
        $this->assertSame([], $this->runner->run(self::at('2026-11-07 23:00', 'Europe/Belgrade')));
    }

    public function testPreviousRunIsNeverInTheFutureAcrossADaylightSavingGap(): void
    {
        $tz = new \DateTimeZone('Europe/Belgrade');      // 2026-03-29: 02:00 jumps to 03:00
        $cron = new \Kestello\Support\CronExpression('30 2 * * *');
        $at = new \DateTimeImmutable('2026-03-29 03:10', $tz);
        $previous = $cron->previousRun($at);
        $this->assertTrue($previous <= $at, 'got ' . $previous->format('c'));
        $this->assertSame('2026-03-28 02:30', $previous->format('Y-m-d H:i'));
    }

    public function testFiltersDisableAndOverlap(): void
    {
        $open = false;
        $this->schedule->call($this->track('office'), 'office')->everyMinute()->between('8:00', '18:00'); // unpadded on purpose
        $this->schedule->call($this->track('gated'), 'gated')->everyMinute()->when(function () use (&$open) {
            return $open;
        });
        $this->schedule->call($this->track('locked'), 'locked')->everyMinute()->withoutOverlapping();
        $this->schedule->call($this->track('off'), 'off')->everyMinute();
        $this->runner->sync(self::at('2026-11-07 07:00'));
        $this->runner->setDisabled('off', true);

        $this->cache->lock('schedule:locked', 600)->acquire(); // a previous run still holds it
        $results = $this->runner->run(self::at('2026-11-07 07:30'));
        $this->assertSame(['office' => 'skipped', 'gated' => 'skipped', 'locked' => 'skipped'], $results);

        $open = true;
        $this->cache->flush();
        $this->assertSame(['office' => 'ok', 'gated' => 'ok', 'locked' => 'ok'], $this->runner->run(self::at('2026-11-07 09:00')));
        $this->assertSame(['office', 'gated', 'locked'], $this->ran, 'disabled task never ran');
    }

    public function testJobsCommandsFailuresAndRecords(): void
    {
        $this->schedule->job(new PingJob())->everyMinute();
        $this->schedule->command('acme:echo one two --loud=yes')->everyMinute();
        $this->schedule->command('acme:echo --fail', 'echo-fail')->everyMinute();
        $this->schedule->command('nope:missing')->everyMinute();
        $this->schedule->call(fn() => throw new \RuntimeException('boom'), 'broken')->everyMinute();
        $this->runner->sync(self::at('2026-11-07 07:00'));

        $results = $this->runner->run(self::at('2026-11-07 07:01'));
        $this->assertSame(['ping-job' => 'ok', 'acme:echo one two --loud=yes' => 'ok', 'echo-fail' => 'failed', 'nope:missing' => 'failed', 'broken' => 'failed'], $results);
        $this->assertCount(1, $this->queue->jobs, 'job task pushed to the queue');

        $rows = array_column(DatabaseManager::getConnection()->table('scheduled_tasks')->get(), null, 'name');
        $this->assertSame('args=one,two loud=yes', trim($rows['acme:echo one two --loud=yes']['last_output']), 'captured, without colour codes');
        $this->assertContains('Exit code 2', $rows['echo-fail']['last_output']);
        $this->assertContains('boom', $rows['broken']['last_output']);
        $this->assertTrue(is_numeric($rows['broken']['last_duration_ms']));

        $this->assertSame('ok', $this->runner->runNow('acme:echo one two --loud=yes'), 'run now ignores the schedule');
        $this->assertTrue(is_array($this->cache->get(ScheduleRunner::HEARTBEAT_KEY)));
        $this->assertSame(['acme:sync', ['a b'], ['x' => '1', 'y' => '']], ScheduleRunner::parseCommandLine('acme:sync "a b" --x=1 --y'));
    }

    public function testBudgetLeavesLateTasksForLater(): void
    {
        $this->schedule->call(fn() => sleep(1), 'slow')->everyMinute();
        $this->schedule->call($this->track('late'), 'late')->everyMinute();
        $this->runner->sync(self::at('2026-11-07 07:00'));

        $results = $this->runner->run(self::at('2026-11-07 07:01'), budget: 1);
        $this->assertSame(['slow' => 'ok', 'late' => 'skipped'], $results);
        $this->assertNull(ScheduleRunner::remaining(), 'budget cleared after the run');
    }
}
