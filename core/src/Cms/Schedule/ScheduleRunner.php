<?php

declare(strict_types=1);

namespace Kestello\Cms\Schedule;

use Closure;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Contracts\Cache\Store as CacheStore;
use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Console\Output;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\QueryBuilder;
use Throwable;

/**
 * Runs due tasks. Called every minute by `schedule:run` (cron), by the web
 * runner, or by POST /system/cron.
 *
 * Each task's due time is claimed with a conditional UPDATE, so a time
 * slot runs exactly once even when cron, the web runner and a second server
 * all fire together. Missed slots (server down, no visitors) are not
 * replayed: the task runs once, for the latest slot. New tasks start with
 * their next slot, never immediately on install.
 */
final class ScheduleRunner
{
    public const string HEARTBEAT_KEY = 'schedule:heartbeat';
    private const string TABLE = 'scheduled_tasks';
    private const int OUTPUT_LIMIT = 10000;

    /** Unix time the current run must finish by (web runner budget); tasks may read it. */
    private static ?int $deadline = null;

    /** @param Closure(string $name): ?Command $commands resolves registered (plugin) commands */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly JobQueue $queue,
        private readonly CacheStore $cache,
        private readonly Closure $commands,
    ) {}

    /** Seconds left in the current run's budget (null: no limit). */
    public static function remaining(): ?int
    {
        return self::$deadline === null ? null : max(0, self::$deadline - time());
    }

    /**
     * @param string $via cron | web | http | cli
     * @return array<string, string> task => ok|failed|skipped
     */
    public function run(?int $now = null, string $via = 'cron', ?int $budget = null): array
    {
        $now ??= time();
        if (\Kestello\Cms\System\Maintenance::pausesBackgroundWork()) {
            return [];          // a restore is rebuilding the database; due tasks run on the next tick
        }
        self::$deadline = $budget !== null ? time() + $budget : null;
        try {
            \Kestello\Framework\Settings\Settings::getInstance()->reload();    // schedule:work runs for days
        } catch (\Throwable) {
        }
        if ($this->cache->get(self::HEARTBEAT_KEY) === null) {
            // The first run: a cached health report still says the scheduler never ran.
            $this->cache->forget(\Kestello\Cms\Health\SiteHealth::CACHE_KEY);
        }
        $this->cache->put(self::HEARTBEAT_KEY, ['at' => time(), 'via' => $via], 86400 * 7);

        try {
            $rows = $this->sync($now);
            $results = [];
            foreach ($this->schedule->tasks() as $name => $task) {
                $row = $rows[$name];
                if ((bool) $row['disabled']) {
                    continue;
                }
                $due = $task->previousDue($now);
                if ($due <= (int) $row['last_due_at'] || !$this->claim($name, $due)) {
                    continue;
                }
                if (self::$deadline !== null && time() >= self::$deadline) {
                    $this->record($name, 'skipped', 0, 'Out of time in this run.');
                    $results[$name] = 'skipped';
                    continue;
                }
                $results[$name] = $task->passesFilters($now) ? $this->execute($task) : $this->record($name, 'skipped', 0, 'Conditions not met.');
            }

            return $results;
        } finally {
            self::$deadline = null;
        }
    }

    /** Run one task now, whether due or not ("run now", schedule:test). */
    public function runNow(string $name): string
    {
        $task = $this->schedule->get($name) ?? throw new \InvalidArgumentException("No scheduled task [{$name}].");
        $this->sync(time());

        return $this->execute($task);
    }

    public function setDisabled(string $name, bool $disabled): void
    {
        if ($this->schedule->get($name) === null) {
            throw new \InvalidArgumentException("No scheduled task [{$name}].");
        }
        $this->sync(time());
        QueryBuilder::table(self::TABLE)->where('name', $name)->update(['disabled' => $disabled ? 1 : 0]);
    }

    /**
     * Task rows (created on first sight, starting from "now").
     *
     * @return array<string, array<string, mixed>>
     */
    public function sync(int $now): array
    {
        $rows = [];
        foreach (QueryBuilder::table(self::TABLE)->get() as $row) {
            $rows[(string) $row['name']] = $row;
        }
        foreach ($this->schedule->tasks() as $name => $task) {
            if (!isset($rows[$name])) {
                $row = ['name' => $name, 'owner' => $task->owner, 'last_due_at' => $now, 'disabled' => 0, 'created_at' => $now];
                try {
                    QueryBuilder::table(self::TABLE)->insert($row, 'name');
                } catch (\PDOException) {
                    $row = QueryBuilder::table(self::TABLE)->where('name', $name)->first() ?? $row; // another runner inserted it
                }
                $rows[$name] = $row + ['last_status' => null, 'last_started_at' => null, 'last_finished_at' => null, 'last_duration_ms' => null, 'last_output' => null];
            }
        }

        return $rows;
    }

    private function claim(string $name, int $due): bool
    {
        return QueryBuilder::table(self::TABLE)->where('name', $name)->where('last_due_at', '<', $due)->update(['last_due_at' => $due]) === 1;
    }

    private function execute(ScheduledTask $task): string
    {
        $lock = null;
        if ($task->overlapMinutes() > 0) {
            $lock = $this->cache->lock('schedule:' . $task->name, $task->overlapMinutes() * 60);
            if (!$lock->acquire()) {
                return $this->record($task->name, 'skipped', 0, 'The previous run is still going.');
            }
        }

        QueryBuilder::table(self::TABLE)->where('name', $task->name)->update(['last_status' => 'running', 'last_started_at' => time()]);
        $started = hrtime(true);
        try {
            $output = ExecutionContext::run($task->owner, fn(): string => $this->invoke($task));
            $status = 'ok';
        } catch (Throwable $e) {
            $output = $e::class . ': ' . $e->getMessage();
            $status = 'failed';
            error_log("[schedule] {$task->name} failed: {$e->getMessage()}");
        } finally {
            $lock?->release();
        }

        return $this->record($task->name, $status, (int) ((hrtime(true) - $started) / 1_000_000), $output);
    }

    private function invoke(ScheduledTask $task): string
    {
        switch ($task->type) {
            case 'job':
                /** @var Job $job */
                $job = $task->target;
                $id = $this->queue->push($job);
                return $id === null ? 'Ran (sync queue) or already pending.' : "Queued job {$id}.";

            case 'command':
                [$name, $arguments, $options] = self::parseCommandLine((string) $task->target);
                $command = ($this->commands)($name) ?? throw new \RuntimeException("Command [{$name}] is not registered.");
                $stream = fopen('php://memory', 'w+');
                $command->setOutput(new Output($stream, ansi: false));
                $code = $command->handle($arguments, $options);
                rewind($stream);
                $out = (string) stream_get_contents($stream);
                fclose($stream);
                if ($code !== 0) {
                    throw new \RuntimeException("Exit code {$code}: " . trim($out));
                }
                return $out;

            default:
                $result = ($task->target)();
                return is_string($result) ? $result : (is_int($result) ? (string) $result : '');
        }
    }

    private function record(string $name, string $status, int $ms, string $output): string
    {
        QueryBuilder::table(self::TABLE)->where('name', $name)->update([
            'last_status' => $status,
            'last_finished_at' => time(),
            'last_duration_ms' => $ms,
            'last_output' => mb_substr($output, 0, self::OUTPUT_LIMIT),
        ]);

        return $status;
    }

    /**
     * "acme:sync --since=1h --force users" → [name, [users], [since => 1h, force => '']]
     *
     * @return array{0: string, 1: list<string>, 2: array<string, string>}
     */
    public static function parseCommandLine(string $line): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $line, $m, PREG_SET_ORDER);
        $tokens = array_map(static fn(array $t) => $t[3] ?? (($t[2] ?? '') !== '' ? $t[2] : $t[1]), $m);
        $name = (string) array_shift($tokens);
        $arguments = [];
        $options = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                [$k, $v] = array_pad(explode('=', substr($token, 2), 2), 2, '');
                $options[$k] = $v;
            } else {
                $arguments[] = $token;
            }
        }

        return [$name, $arguments, $options];
    }
}
