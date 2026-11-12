<?php

declare(strict_types=1);

namespace Kestello\Tests\Queue;

use Closure;
use Kestello\Cms\Queue\CallQueuedListener;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Queue\Middleware\RateLimited;
use Kestello\Cms\Queue\Middleware\WithoutOverlapping;
use Kestello\Cms\Queue\Worker;
use Kestello\Contracts\Queue\Job;
use Kestello\Contracts\Queue\JobMiddleware;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Queued;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Framework\Testing\TestCase;

final class FlowLog
{
    /** @var list<string> */
    public static array $lines = [];
    /** @var array<string, int> */
    public static array $failures = [];
    public static ?ArrayStore $cache = null;
}

final class StepJob implements Job
{
    public function __construct(public readonly string $name) {}

    public function retries(): int
    {
        return 0;
    }

    public function handle(): void
    {
        if ((FlowLog::$failures[$this->name] ?? 0) > 0) {
            FlowLog::$failures[$this->name]--;
            throw new \RuntimeException("{$this->name} broke");
        }
        FlowLog::$lines[] = $this->name . '@' . ExecutionContext::current();
    }
}

final class LimitedJob implements Job
{
    public function __construct(public readonly string $name) {}

    public function middleware(): array
    {
        return [new RateLimited('flow', max: 2, perSeconds: 3600, cache: FlowLog::$cache)];
    }

    public function handle(): void
    {
        FlowLog::$lines[] = $this->name;
    }
}

final class ExclusiveJob implements Job
{
    public function __construct(public readonly string $name) {}

    public function middleware(): array
    {
        return [new TraceMiddleware('outer'), new WithoutOverlapping('flow-lock', releaseAfter: 30, cache: FlowLog::$cache), new TraceMiddleware('inner')];
    }

    public function handle(): void
    {
        FlowLog::$lines[] = $this->name;
    }
}

final class TraceMiddleware implements JobMiddleware
{
    public function __construct(private readonly string $label) {}

    public function handle(Job $job, Closure $next): void
    {
        FlowLog::$lines[] = "{$this->label}>";
        $next($job);
        FlowLog::$lines[] = "<{$this->label}";
    }
}

final class OrderPlaced
{
    public function __construct(public readonly int $orderId, public readonly array $items = []) {}
}

final class ObjectEvent
{
    public function __construct(public readonly \stdClass $thing) {}
}

#[Queued(queue: 'mail')]
final class QueuedOrderListener
{
    public function handle(OrderPlaced|ObjectEvent $event): void
    {
        FlowLog::$lines[] = 'listener:' . ($event instanceof OrderPlaced ? $event->orderId : 'object') . '@' . ExecutionContext::current();
    }
}

/** Chains, batches, job middleware and queued listeners. */
final class QueueFlowTest extends TestCase
{
    private ArrayQueue $backend;
    private JobQueue $queue;
    private EventDispatcher $events;

    protected function setUp(): void
    {
        FlowLog::$lines = [];
        FlowLog::$failures = [];
        FlowLog::$cache = new ArrayStore();

        $db = Connection::sqliteMemory();
        DatabaseManager::setConnection($db);
        (require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_04_000006_create_jobs_tables.php')->up();
        (require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_05_000001_create_job_batches_table.php')->up();
        (require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_05_000012_create_job_batch_jobs_table.php')->up();

        $this->backend = new ArrayQueue();
        $this->events = new EventDispatcher();
        $this->queue = $this->manager($this->backend);
    }

    protected function tearDown(): void
    {
        ExecutionContext::reset();
        DatabaseManager::reset();
    }

    private function manager(\Kestello\Contracts\Queue\Queue $backend): JobQueue
    {
        $q = new JobQueue();
        $q->setBackend(static fn() => $backend);
        $q->setContainer(new \Kestello\Framework\Core\Container(), $this->events);
        foreach ([StepJob::class, LimitedJob::class, ExclusiveJob::class] as $class) {
            $q->job($class);
        }

        return $q;
    }

    /** @return array{done: int, retry: int, failed: int, released: int, skipped: int} */
    private function drain(int $max = 50): array
    {
        $worker = new Worker($this->queue, new ArrayStore(), sleep: static fn() => null);
        $worker->run(JobQueue::QUEUES, stopWhenEmpty: true, maxJobs: $max, memoryMb: QueueTest::memoryMb());

        return $worker->counts;
    }

    public function testChainsRunInOrderAndStopAtAFailure(): void
    {
        $this->queue->chain([new StepJob('a'), new StepJob('b'), new StepJob('c')]);
        $this->assertCount(1, $this->backend->jobs, 'only the first step is queued');
        $this->drain();
        $this->assertSame(['a@core', 'b@core', 'c@core'], FlowLog::$lines);

        FlowLog::$lines = [];
        FlowLog::$failures = ['y' => 1];
        $this->queue->chain([new StepJob('x'), new StepJob('y'), new StepJob('z')]);
        $counts = $this->drain();
        $this->assertSame(['x@core'], FlowLog::$lines, 'z never runs after y failed');
        $this->assertSame([1, 1], [$counts['done'], $counts['failed']]);
        $this->assertSame([], $this->backend->jobs);

        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->queue->chain([]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->queue->chain([new StepJob('a'), new UnregisteredFlowJob()]), 'every step must be registered up front');
    }

    public function testBatchesTrackProgressAndRunTheirCallbacks(): void
    {
        $id = $this->queue->batch([new StepJob('1'), new StepJob('2'), new StepJob('3')])
            ->name('Numbers')->then(new StepJob('then'))->catch(new StepJob('catch'))->finally(new StepJob('finally'))
            ->dispatch();

        $batch = $this->queue->batches()->find($id);
        $this->assertSame(['Numbers', 3, 3, 0, 'running'], [$batch['name'], $batch['total'], $batch['pending'], $batch['progress'], $batch['status']]);

        $this->drain(1);
        $this->assertSame(33, $this->queue->batches()->find($id)['progress']);
        $this->drain();
        $this->assertSame(['1@core', '2@core', '3@core', 'then@core', 'finally@core'], FlowLog::$lines);
        $this->assertSame(['finished', 100, 0], [$this->queue->batches()->find($id)['status'], $this->queue->batches()->find($id)['progress'], $this->queue->batches()->find($id)['failed']]);
    }

    public function testAJobThatRunsTwiceCountsOnceInItsBatch(): void
    {
        $batches = $this->queue->batches();
        $batches->create('00000000-0000-4000-8000-000000000001', 'twice', 'core', 2, []);
        $batches->settle('00000000-0000-4000-8000-000000000001', false, '11111111-1111-4111-8111-111111111111');
        $batches->settle('00000000-0000-4000-8000-000000000001', false, '11111111-1111-4111-8111-111111111111');   // the same job, run again
        $this->assertSame(50, $batches->find('00000000-0000-4000-8000-000000000001')['progress']);
    }

    public function testAFailureCancelsTheBatchUnlessFailuresAreAllowed(): void
    {
        FlowLog::$failures = ['b' => 1];
        $id = $this->queue->batch([new StepJob('a'), new StepJob('b'), new StepJob('c'), new StepJob('d')])
            ->then(new StepJob('then'))->catch(new StepJob('catch'))->finally(new StepJob('finally'))->dispatch();
        $counts = $this->drain();
        $this->assertSame(['a@core', 'catch@core', 'finally@core'], FlowLog::$lines, 'c and d skipped; then not run');
        $this->assertSame([2, 1], [$counts['skipped'], $counts['failed']]);
        $this->assertSame(['cancelled', 1], [$this->queue->batches()->find($id)['status'], $this->queue->batches()->find($id)['failed']]);

        FlowLog::$lines = [];
        FlowLog::$failures = ['q' => 1];
        $id = $this->queue->batch([new StepJob('p'), new StepJob('q'), new StepJob('r')])->allowFailures()
            ->then(new StepJob('then'))->catch(new StepJob('catch'))->dispatch();
        $this->drain();
        $this->assertSame(['p@core', 'r@core', 'catch@core', 'then@core'], FlowLog::$lines, 'catch once (queued behind r), the rest still runs');
        $this->assertSame('finished_with_failures', $this->queue->batches()->find($id)['status']);

        // Cancelling from outside (the admin): remaining jobs are skipped.
        FlowLog::$lines = [];
        $id = $this->queue->batch([new StepJob('m'), new StepJob('n')])->finally(new StepJob('finally'))->dispatch();
        $this->assertTrue($this->queue->batches()->cancel($id));
        $this->assertFalse($this->queue->batches()->cancel($id), 'only once');
        $this->drain();
        $this->assertSame(['finally@core'], FlowLog::$lines);
        $this->assertSame('cancelled', $this->queue->batches()->find($id)['status']);
    }

    public function testBatchesOnTheSyncDriver(): void
    {
        $this->queue = $this->manager(new SyncQueue());
        FlowLog::$failures = ['2' => 1];
        $id = $this->queue->batch([new StepJob('1'), new StepJob('2'), new StepJob('3')])->allowFailures()->finally(new StepJob('fin'))->dispatch();
        $this->assertSame(['1@core', '3@core', 'fin@core'], FlowLog::$lines, 'a failure inside a batch does not abort dispatch');
        $this->assertSame(['finished_with_failures', 1], [$this->queue->batches()->find($id)['status'], $this->queue->batches()->find($id)['failed']]);
    }

    public function testRateLimitedJobsWaitWithoutUsingAttempts(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $this->queue->push(new LimitedJob($n));
        }
        $counts = $this->drain(10);
        $this->assertSame(['a', 'b'], FlowLog::$lines);
        $this->assertSame(2, $counts['released']);
        $waiting = array_values($this->backend->jobs);
        $this->assertCount(2, $waiting);
        $this->assertSame([0, 0], array_column($waiting, 'attempts'), 'the attempt was given back');
        $this->assertTrue($waiting[0]['available'] > time(), 'released until the window ends (windows follow the clock, so this may be seconds away)');
    }

    public function testWithoutOverlappingAndMiddlewareOrder(): void
    {
        $this->queue->push(new ExclusiveJob('one'));
        $this->drain();
        $this->assertSame(['outer>', 'inner>', 'one', '<inner', '<outer'], FlowLog::$lines, 'first listed runs outermost');

        FlowLog::$lines = [];
        $held = FlowLog::$cache->lock('job-overlap:flow-lock', 60);
        $this->assertTrue($held->acquire());
        $this->queue->push(new ExclusiveJob('two'));
        $counts = $this->drain();
        $this->assertSame(1, $counts['released']);
        $this->assertSame(['outer>'], FlowLog::$lines, 'stopped at the lock');
        $held->release();
    }

    public function testQueuedListenersRunInTheBackgroundAsTheirOwner(): void
    {
        $this->queue->wireListeners($this->events);
        ExecutionContext::run('plugin:shop', fn() => $this->events->on(OrderPlaced::class, QueuedOrderListener::class));
        $this->events->on(OrderPlaced::class, fn(OrderPlaced $e) => FlowLog::$lines[] = "inline:{$e->orderId}");

        $this->events->dispatch(new OrderPlaced(42, ['sku' => 'A1']));
        $this->assertSame(['inline:42'], FlowLog::$lines, 'the queued listener did not run during dispatch');
        $job = array_values($this->backend->jobs)[0];
        $this->assertSame('mail', $job['queue']);
        $this->assertSame(CallQueuedListener::class, json_decode($job['payload'], true)['job']);

        $this->drain();
        $this->assertSame(['inline:42', 'listener:42@plugin:shop'], FlowLog::$lines);

        // A forged row naming a listener that isn't registered for the event fails instead of running.
        $this->backend->push('default', json_encode(['job' => CallQueuedListener::class, 'args' => ['listener' => QueuedOrderListener::class, 'event' => \stdClass::class, 'data' => []]]));
        $counts = $this->drain();
        $this->assertSame([0, 1], [$counts['done'], $counts['retry']], 'refused, not run (retried later with backoff)');
        $this->assertSame(['inline:42', 'listener:42@plugin:shop'], FlowLog::$lines);
        $this->backend->clear();

        // Events holding objects can't be stored: the listener runs inline.
        FlowLog::$lines = [];
        $this->events->on(ObjectEvent::class, QueuedOrderListener::class);
        $this->events->dispatch(new ObjectEvent(new \stdClass()));
        $this->assertSame(['listener:object@core'], FlowLog::$lines);
    }
    public function testAStopSignalReachesWhoStartedTheWorkerAndTheirHandlerIsPutBack(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            $this->assertTrue(true, 'no pcntl or posix: nothing is trapped');
            return;
        }
        $heard = 0;
        pcntl_signal(SIGTERM, function () use (&$heard): void {
            $heard++;   // stands in for schedule:work
        });
        try {
            $worker = new Worker($this->queue, new ArrayStore(), sleep: static function (): void {
                posix_kill(getmypid(), SIGTERM);
            });
            $this->assertSame('signal', $worker->run(JobQueue::QUEUES, memoryMb: QueueTest::memoryMb()), 'the worker stops');
            $this->assertSame(1, $heard, 'and the command that started it hears the signal too');

            posix_kill(getmypid(), SIGTERM);
            pcntl_signal_dispatch();
            $this->assertSame(2, $heard, 'afterwards the earlier handler is back, not the worker\'s');
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
        }
    }
}

final class UnregisteredFlowJob implements Job
{
    public function handle(): void {}
}
