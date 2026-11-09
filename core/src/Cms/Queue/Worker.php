<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue;

use Closure;
use Kestello\Contracts\Cache\Store as CacheStore;

/**
 * The job loop behind `queue:work`.
 *
 * Stops (exit code 0, let systemd/supervisor/Docker restart it) when:
 * the max job count or time is reached, memory passes the limit, the queue
 * is empty with stopWhenEmpty, SIGTERM/SIGINT arrives (after the current
 * job), or `queue:restart` was run after this worker started.
 */
final class Worker
{
    public const string RESTART_KEY = 'queue:restart';
    public const string HEARTBEAT_KEY = 'queue:heartbeat';

    private bool $stop = false;

    /** @var array{done: int, retry: int, failed: int, released: int, skipped: int} */
    public array $counts = ['done' => 0, 'retry' => 0, 'failed' => 0, 'released' => 0, 'skipped' => 0];

    /** @param (Closure(string): void)|null $log */
    public function __construct(
        private readonly JobQueue $queue,
        private readonly CacheStore $cache,
        private readonly ?Closure $log = null,
        private readonly ?Closure $sleep = null,
    ) {}

    /**
     * @param list<string> $queues priority order
     * @return string why it stopped
     */
    public function run(
        array $queues,
        bool $stopWhenEmpty = false,
        int $maxJobs = 0,
        int $maxTime = 0,
        int $memoryMb = 128,
        int $sleep = 3,
    ): string {
        $restore = $this->trapSignals();
        try {
            return $this->loop($queues, $stopWhenEmpty, $maxJobs, $maxTime, $memoryMb, $sleep);
        } finally {
            $restore();     // the scheduler runs a worker every minute: leave its handlers as they were
        }
    }

    /** @param list<string> $queues */
    private function loop(array $queues, bool $stopWhenEmpty, int $maxJobs, int $maxTime, int $memoryMb, int $sleep): string
    {
        $started = time();
        $restartMark = $this->cache->get(self::RESTART_KEY);

        $jobs = 0;
        while (true) {
            $this->cache->put(self::HEARTBEAT_KEY, ['at' => time(), 'pid' => getmypid(), 'host' => gethostname() ?: '?'], 3600);

            // During a restore the database is being rebuilt: take no jobs until it is done.
            $reserved = \Kestello\Cms\System\Maintenance::pausesBackgroundWork()
                ? null
                : $this->queue->backend()->reserve($queues, JobQueue::DEFAULT_TIMEOUT + 30);
            if ($reserved !== null) {
                // Now that we know which job it is, hold it for that job's own timeout.
                $visibility = $this->queue->visibilityFor($reserved->payload);
                if ($visibility > JobQueue::DEFAULT_TIMEOUT + 30) {
                    $this->queue->backend()->touch($reserved, $visibility);
                }
                // A long-running worker must see settings saved since it started (mail server, site address).
                try {
                    \Kestello\Framework\Settings\Settings::getInstance()->reload();
                } catch (\Throwable) {
                    // keeps the values it has
                }
                $result = $this->queue->process($reserved);
                $this->counts[$result]++;
                $jobs++;
                $this->say("{$result}: job {$reserved->id} on {$reserved->queue} (attempt {$reserved->attempts})");
            }

            $reason = match (true) {
                $this->stop => 'signal',
                $maxJobs > 0 && $jobs >= $maxJobs => 'max jobs',
                $maxTime > 0 && time() - $started >= $maxTime => 'max time',
                memory_get_usage(true) > $memoryMb * 1024 * 1024 => 'memory limit',
                $this->cache->get(self::RESTART_KEY) !== $restartMark => 'restart requested',
                $reserved === null && $stopWhenEmpty => 'queue empty',
                default => null,
            };
            if ($reason !== null) {
                return $reason;
            }

            if ($reserved === null) {
                $this->sleep ? ($this->sleep)($sleep) : sleep($sleep);
            }
        }
    }

    /** Ask every worker to exit after its current job (they are restarted by their supervisor). */
    public static function requestRestart(CacheStore $cache): void
    {
        $cache->put(self::RESTART_KEY, microtime(true), 0);
    }

    /**
     * Stop after the current job on SIGTERM, SIGINT or SIGQUIT. A handler that
     * was already there (schedule:work's) still hears the signal, so the command
     * that started this worker stops too.
     *
     * @return Closure(): void puts back the handlers that were there before
     */
    private function trapSignals(): Closure
    {
        if (!function_exists('pcntl_signal') || PHP_SAPI !== 'cli') {
            return static function (): void {};
        }
        $async = pcntl_async_signals(true);
        $previous = [];
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            $before = pcntl_signal_get_handler($signal);   // a handler, or SIG_DFL / SIG_IGN
            $previous[$signal] = $before;
            pcntl_signal($signal, function (int $signo, mixed $info = null) use ($before): void {
                $this->stop = true;
                if (is_callable($before)) {
                    // PHP passes a handler the signal and its info; the stub says it takes none.
                    $before($signo, $info); // @phpstan-ignore arguments.count
                }
            });
        }

        return static function () use ($previous, $async): void {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($async);
        };
    }

    private function say(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }
}
