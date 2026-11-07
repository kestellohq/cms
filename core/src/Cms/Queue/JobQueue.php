<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Audit\AuditLog;
use Kestello\Cms\Events\JobFailed;
use Kestello\Contracts\Queue\Job;
use Kestello\Contracts\Queue\JobMiddleware;
use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Support\Json;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * $cms->queue: push jobs, and run them (workers call process()).
 *
 *   $cms->queue->job(SendDigest::class);                       // register (once)
 *   $cms->queue->push(new SendDigest($userId));
 *   $cms->queue->push(new Reindex(), delay: 300, queue: 'low');
 *   $cms->queue->chain([new ImportRows($file), new Reindex(), new NotifyDone($userId)]);
 *   $cms->queue->batch($jobs)->name('Thumbnails')->then(new NotifyDone($userId))->dispatch();
 *
 * Jobs may list middleware (RateLimited, WithoutOverlapping, your own
 * JobMiddleware) in middleware(); a middleware can postpone a job with
 * ReleaseJob without using up an attempt.
 *
 * Payloads are JSON: the class name and constructor arguments. Only
 * registered job classes run, and they run as the package that registered
 * them (not whatever a queue row claims), so a tampered row can neither
 * instantiate arbitrary classes nor borrow core's identity.
 *
 * Queues, in default priority order: high, default, mail, media, low.
 *
 * @api
 */
final class JobQueue
{
    public const array QUEUES = ['high', 'default', 'mail', 'media', 'low'];
    public const int DEFAULT_TIMEOUT = 60;
    public const int DEFAULT_RETRIES = 3;

    /** @var array<class-string<Job>, string> class => owner */
    private array $jobs = [];

    /** @var (Closure(): Queue)|null */
    private ?Closure $backend = null;
    private ?Queue $resolved = null;
    private ?Container $container = null;
    private ?EventDispatcher $events = null;
    private ?Batches $batches = null;

    /** Batch progress and cancellation (job_batches table). */
    public function batches(): Batches
    {
        return $this->batches ??= new Batches();
    }

    /** @param Closure(): Queue $backend */
    public function setBackend(Closure $backend): void
    {
        $this->backend = $backend;
        $this->resolved = null;
    }

    public function setContainer(Container $container, ?EventDispatcher $events = null): void
    {
        $this->container = $container;
        $this->events = $events;
    }

    /**
     * Queue #[Queued] listeners of these events. An event that can't be
     * stored as JSON (it holds objects) runs the listener inline, logged.
     */
    public function wireListeners(EventDispatcher $events): void
    {
        ExecutionContext::run('core', fn() => $this->job(CallQueuedListener::class));
        $events->setQueuer(function (string $listener, object $event, \Kestello\Framework\Events\Queued $options): bool {
            try {
                $data = self::arguments($event);
            } catch (InvalidArgumentException $e) {
                error_log("[queue] {$listener} runs inline: " . $event::class . ' cannot be queued (' . $e->getMessage() . ')');
                return false;
            }
            $this->pushDescriptor(['job' => CallQueuedListener::class, 'args' => ['listener' => $listener, 'event' => $event::class, 'data' => $data]], $options->delay, $options->queue, null);

            return true;
        });
    }

    public function backend(): Queue
    {
        return $this->resolved ??= ($this->backend !== null ? ($this->backend)() : new SyncQueue());
    }

    /**
     * Allow a job class to run. The class belongs to whoever registers it
     * first (core, or the plugin or theme whose code is running); a second
     * owner registering the same class is refused.
     *
     * @param class-string<Job> $class
     */
    public function job(string $class): void
    {
        if (!class_exists($class) || !is_subclass_of($class, Job::class)) {
            throw new InvalidArgumentException("[{$class}] is not a class implementing " . Job::class . '.');
        }
        if (!method_exists($class, 'handle')) {
            throw new InvalidArgumentException("Job [{$class}] has no handle() method.");
        }
        $owner = ExecutionContext::current();
        if (isset($this->jobs[$class]) && $this->jobs[$class] !== $owner) {
            throw new InvalidArgumentException("Job [{$class}] is already registered by {$this->jobs[$class]}.");
        }
        $this->jobs[$class] = $owner;
    }

    /** @return array<class-string<Job>, string> */
    public function registered(): array
    {
        return $this->jobs;
    }

    /**
     * Queue a job. Returns its id; null when an identical unique job is
     * already pending, or when the sync driver ran it right away.
     */
    public function push(Job $job, int $delay = 0, ?string $queue = null): ?string
    {
        return $this->pushDescriptor($this->describe($job), $delay, $queue ?? 'default', self::option($job, 'uniqueKey', null));
    }

    /**
     * Run jobs one after another: each is queued when the previous one
     * succeeded. A job that fails for good drops the rest of the chain.
     *
     * @param list<Job> $jobs
     */
    public function chain(array $jobs, int $delay = 0, ?string $queue = null): ?string
    {
        if ($jobs === [] || !array_is_list($jobs)) {
            throw new InvalidArgumentException('A chain needs a list of jobs.');
        }
        $steps = array_map(fn($job) => $job instanceof Job ? $this->describe($job) : throw new InvalidArgumentException('Chains hold jobs only.'), $jobs);
        $first = array_shift($steps);

        return $this->pushDescriptor($first, $delay, $queue ?? 'default', null, $steps === [] ? [] : ['chain' => $steps]);
    }

    /** @param list<Job> $jobs */
    public function batch(array $jobs): PendingBatch
    {
        return new PendingBatch($this, $jobs);
    }

    /**
     * @param list<Job> $jobs
     * @internal PendingBatch::dispatch()
     */
    public function dispatchBatch(array $jobs, string $name, ?string $queue, bool $allowFailures, ?Job $then, ?Job $catch, ?Job $finally): string
    {
        if ($jobs === [] || !array_is_list($jobs)) {
            throw new InvalidArgumentException('A batch needs a list of jobs.');
        }
        $steps = array_map(fn($job) => $job instanceof Job ? $this->describe($job) : throw new InvalidArgumentException('Batches hold jobs only.'), $jobs);
        $options = array_filter([
            'then' => $then !== null ? $this->describe($then) : null,
            'catch' => $catch !== null ? $this->describe($catch) : null,
            'finally' => $finally !== null ? $this->describe($finally) : null,
            'allow_failures' => $allowFailures ?: null,
            'queue' => $queue,
        ], static fn($v) => $v !== null);

        $id = self::uuid();
        $this->batches()->create($id, $name !== '' ? $name : sprintf('%d jobs', count($steps)), ExecutionContext::current(), count($steps), $options);
        foreach ($steps as $step) {
            // No unique keys inside a batch: a dropped duplicate would never settle.
            $this->pushDescriptor($step, 0, $queue ?? 'default', null, ['batch' => $id]);
        }

        return $id;
    }

    /**
     * The job's class and constructor arguments (registered classes only).
     *
     * @return array{job: string, args: array<string, mixed>}
     */
    private function describe(Job $job): array
    {
        $class = $job::class;
        if (!isset($this->jobs[$class])) {
            throw new InvalidArgumentException("Register [{$class}] with \$cms->queue->job() before pushing it.");
        }

        return ['job' => $class, 'args' => self::arguments($job)];
    }

    /**
     * @param array{job: string, args: array<string, mixed>} $descriptor
     * @param array<string, mixed> $extra chain / batch
     */
    private function pushDescriptor(array $descriptor, int $delay, string $queue, ?string $uniqueKey = null, array $extra = []): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $queue) !== 1) {
            throw new InvalidArgumentException("Invalid queue name [{$queue}].");
        }

        $payload = Json::encode([
            'uuid' => self::uuid(),
            'job' => $descriptor['job'],
            'args' => $descriptor['args'],
            'pushed_by' => ExecutionContext::current(),
            'pushed_at' => time(),
            ...$extra,
        ]);

        $backend = $this->backend();
        if ($backend instanceof SyncQueue) {
            $this->runSync($payload, $queue);
            return null;
        }

        return $backend->push($queue, $payload, $delay, $uniqueKey);
    }

    /**
     * What happens after a job settles: the next chain step on success, the
     * batch's counters (and maybe its callbacks) either way.
     *
     * @param array<string, mixed> $data
     */
    private function afterSettled(array $data, string $queue, bool $succeeded): void
    {
        try {
            if ($succeeded && is_array($data['chain'] ?? null) && $data['chain'] !== []) {
                $steps = array_values($data['chain']);
                $next = array_shift($steps);
                if (is_array($next) && is_string($next['job'] ?? null) && is_array($next['args'] ?? null)) {
                    $this->pushDescriptor(['job' => $next['job'], 'args' => $next['args']], 0, $queue, null, $steps === [] ? [] : ['chain' => $steps]);
                }
            } elseif (!$succeeded && is_array($data['chain'] ?? null) && $data['chain'] !== []) {
                error_log('[queue] ' . ($data['job'] ?? '?') . ' failed; dropped ' . count($data['chain']) . ' chained job(s).');
            }

            if (is_string($data['batch'] ?? null) && DatabaseManager::hasConnection()) {
                foreach ($this->batches()->settle($data['batch'], !$succeeded, is_string($data['uuid'] ?? null) ? $data['uuid'] : null) as $callback) {
                    if (is_array($callback) && is_string($callback['job'] ?? null) && is_array($callback['args'] ?? null)) {
                        $this->pushDescriptor(['job' => $callback['job'], 'args' => $callback['args']], 0, $queue);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[queue] could not continue after ' . ($data['job'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * Run one reserved job: success deletes it, failure releases it for a
     * retry (with backoff) or moves it to failed_jobs. A ReleaseJob from
     * middleware puts it back without counting the attempt; jobs of a
     * cancelled batch are skipped.
     *
     * @return 'done'|'retry'|'failed'|'released'|'skipped'
     */
    public function process(ReservedJob $reserved): string
    {
        $backend = $this->backend();
        $data = null;
        try {
            $data = Json::decode($reserved->payload);
            $job = $this->build(is_array($data) ? $data : []);
        } catch (Throwable $e) {
            $this->fail($reserved, is_array($data) ? $data : [], $e);
            $backend->delete($reserved);
            if (is_array($data)) {
                $this->afterSettled($data, $reserved->queue, false);
            }
            return 'failed';
        }

        if (is_string($data['batch'] ?? null) && DatabaseManager::hasConnection() && $this->batches()->isCancelled($data['batch'])) {
            $backend->delete($reserved);
            $this->afterSettled(['batch' => $data['batch'], 'uuid' => $data['uuid'] ?? null], $reserved->queue, true);
            return 'skipped';
        }

        $max = 1 + max(0, (int) self::option($job, 'retries', self::DEFAULT_RETRIES));
        if ($reserved->attempts > $max) {
            // The previous worker died mid-job (attempts are counted at reservation).
            $this->fail($reserved, $data, new \RuntimeException("Exceeded {$max} attempts (the worker may have crashed or timed out)."));
            $backend->delete($reserved);
            $this->afterSettled($data, $reserved->queue, false);
            return 'failed';
        }

        try {
            $this->run($job);
        } catch (ReleaseJob $release) {
            $backend->release($reserved, max(0, $release->delay), refund: true);
            return 'released';
        } catch (Throwable $e) {
            if ($reserved->attempts < $max) {
                $backoff = self::option($job, 'backoff', null, $reserved->attempts);
                $backend->release($reserved, max(0, $backoff === null ? self::defaultBackoff($reserved->attempts) : (int) $backoff));
                error_log("[queue] {$data['job']} attempt {$reserved->attempts}/{$max} failed, will retry: " . $e->getMessage());
                return 'retry';
            }
            $this->fail($reserved, $data, $e);
            $backend->delete($reserved);
            $this->afterSettled($data, $reserved->queue, false);
            return 'failed';
        }

        $backend->delete($reserved);
        $this->afterSettled($data, $reserved->queue, true);

        return 'done';
    }

    /** Seconds a worker may hold this payload before others can take it. */
    public function visibilityFor(string $payload): int
    {
        try {
            $data = Json::decode($payload);
            $job = $this->build(is_array($data) ? $data : []);
            return (int) self::option($job, 'timeout', self::DEFAULT_TIMEOUT) + 30;
        } catch (Throwable) {
            return self::DEFAULT_TIMEOUT + 30;
        }
    }

    /** @param array<string, mixed> $data */
    public function build(array $data): Job
    {
        $class = $data['job'] ?? null;
        if (!is_string($class) || !isset($this->jobs[$class])) {
            throw new \RuntimeException('Job class [' . (is_string($class) ? $class : '?') . '] is not registered (is its plugin active?).');
        }
        $args = $data['args'] ?? [];
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            throw new \RuntimeException('Malformed job arguments.');
        }

        return new $class(...$args);
    }

    /** Run a job as its registered owner, injecting handle()'s dependencies. */
    public function run(Job $job): void
    {
        $owner = $this->jobs[$job::class] ?? throw new \RuntimeException('Job [' . $job::class . '] is not registered.');
        $timeout = (int) self::option($job, 'timeout', self::DEFAULT_TIMEOUT);

        ExecutionContext::run($owner, function () use ($job, $timeout): void {
            $alarm = function_exists('pcntl_alarm') && PHP_SAPI === 'cli';
            if ($alarm) {
                pcntl_async_signals(true);
                pcntl_signal(SIGALRM, static function () use ($job, $timeout): never {
                    // A hung job can't block the worker forever: exit, and the reservation expiry retries it.
                    error_log('[queue] ' . $job::class . " exceeded its {$timeout}s timeout; worker exiting.");
                    exit(1);
                });
                pcntl_alarm(max(1, $timeout));
            }
            try {
                $handle = function (Job $job): void {
                    $method = new \ReflectionMethod($job, 'handle');
                    $job->handle(...$this->dependencies($method));
                };
                $middleware = (array) self::option($job, 'middleware', []);
                foreach (array_reverse($middleware) as $layer) {
                    if (!$layer instanceof JobMiddleware) {
                        throw new \RuntimeException($job::class . '::middleware() must return JobMiddleware instances.');
                    }
                    $next = $handle;
                    $handle = static fn(Job $job) => $layer->handle($job, $next);
                }
                $handle($job);
            } finally {
                if ($alarm) {
                    pcntl_alarm(0);
                }
            }
        });
    }

    /** @return list<mixed> */
    private function dependencies(\ReflectionMethod $method): array
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $name = $type->getName();
                if ($name === EventDispatcher::class && $this->events !== null) {
                    $args[] = $this->events;
                    continue;
                }
                if ($name === \Kestello\Cms\Cms::class && \Kestello\Cms\Cms::instance() !== null) {
                    $args[] = \Kestello\Cms\Cms::instance();
                    continue;
                }
                if ($this->container !== null && ($this->container->has($name) || class_exists($name))) {
                    $args[] = $this->container->make($name);
                    continue;
                }
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            throw new \RuntimeException("Cannot inject \${$param->getName()} into " . $method->class . '::handle().');
        }

        return $args;
    }

    private function runSync(string $payload, string $queue): void
    {
        $data = Json::decode($payload);
        $job = $this->build($data);
        if (is_string($data['batch'] ?? null) && DatabaseManager::hasConnection() && $this->batches()->isCancelled($data['batch'])) {
            $this->afterSettled(['batch' => $data['batch'], 'uuid' => $data['uuid'] ?? null], $queue, true);
            return;
        }
        try {
            $this->run($job);
        } catch (ReleaseJob $release) {
            // The sync driver cannot wait; the job is skipped (use a real queue for rate-limited work).
            error_log('[queue] ' . $job::class . ' released on the sync driver and skipped: ' . $release->getMessage());
            return;
        } catch (Throwable $e) {
            $this->fail(new ReservedJob('sync', $queue, $payload, 1, ''), $data, $e);
            $this->afterSettled($data, $queue, false);
            if (is_string($data['batch'] ?? null)) {
                return; // the batch records it; the other jobs still run
            }
            throw $e;
        }
        $this->afterSettled($data, $queue, true);
    }

    /** @param array<string, mixed> $data */
    private function fail(ReservedJob $reserved, array $data, Throwable $e): void
    {
        $class = is_string($data['job'] ?? null) ? $data['job'] : '?';
        $owner = $this->jobs[$class] ?? 'unknown';
        $message = $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        error_log("[queue] {$class} failed for good (owner {$owner}): " . $e->getMessage());

        if (DatabaseManager::hasConnection()) {
            try {
                QueryBuilder::table('failed_jobs')->insert([
                    'uuid' => is_string($data['uuid'] ?? null) && strlen($data['uuid']) === 36 ? $data['uuid'] : self::uuid(),
                    'queue' => $reserved->queue,
                    'job' => mb_substr($class, 0, 255),
                    'owner' => mb_substr($owner, 0, 100),
                    'payload' => $reserved->payload,
                    'exception' => mb_substr($message, 0, 20000),
                    'attempts' => $reserved->attempts,
                    'failed_at' => time(),
                ]);
            } catch (Throwable $inner) {
                error_log('[queue] could not record the failed job: ' . $inner->getMessage());
            }
        }

        $this->events?->dispatch(new JobFailed($class, $reserved->queue, $owner, $e));
    }

    /**
     * Constructor arguments read from same-named properties.
     *
     * @return array<string, mixed>
     */
    public static function arguments(object $job): array
    {
        $ref = new ReflectionClass($job);
        $args = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
            $name = $param->getName();
            if (!$ref->hasProperty($name)) {
                throw new InvalidArgumentException($job::class . " must expose constructor argument \${$name} as a property.");
            }
            $value = $ref->getProperty($name)->getValue($job);
            self::assertPlain($value, $job::class . '::$' . $name);
            $args[$name] = $value;
        }

        return $args;
    }

    private static function assertPlain(mixed $value, string $where): void
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                self::assertPlain($v, $where);
            }
            return;
        }
        if (!is_null($value) && !is_scalar($value)) {
            throw new InvalidArgumentException("{$where}: job arguments must be scalars, null or arrays (store ids, not objects).");
        }
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("{$where}: not a finite number.");
        }
    }

    private static function option(Job $job, string $method, mixed $default, mixed ...$args): mixed
    {
        return method_exists($job, $method) ? $job->{$method}(...$args) : $default;
    }

    public static function defaultBackoff(int $attempt): int
    {
        return min(3600, 10 * 2 ** max(0, $attempt - 1)); // 10 s, 20 s, 40 s… capped at an hour
    }

    /**
     * Payload with secret-looking string values masked (for CLI/admin display).
     *
     * @return array<string, mixed>
     */
    public static function displayPayload(string $payload): array
    {
        $data = Json::tryDecode($payload);
        if (!is_array($data)) {
            return ['invalid' => true];
        }
        if (is_array($data['args'] ?? null)) {
            $data['args'] = AuditLog::redact($data['args']);
        }
        foreach ((array) ($data['chain'] ?? []) as $i => $step) {
            if (is_array($step) && is_array($step['args'] ?? null)) {
                $data['chain'][$i]['args'] = AuditLog::redact($step['args']);
            }
        }

        return $data;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
