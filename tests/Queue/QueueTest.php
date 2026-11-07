<?php

declare(strict_types=1);

namespace Kestello\Tests\Queue;

use Kestello\Cms\Events\JobFailed;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Queue\Worker;
use Kestello\Contracts\Queue\Job;
use Kestello\Contracts\Queue\Queue;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Framework\Queue\DatabaseQueue;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Json;

/** Records what ran. */
final class Ledger
{
    /** @var list<string> */
    public static array $ran = [];
    /** @var array<string, int> */
    public static array $failuresLeft = [];
}

final class RecordJob implements Job
{
    public function __construct(public readonly string $name, public readonly array $tags = []) {}

    public function handle(Ledger $ledger, ArrayStore $store): void
    {
        Ledger::$ran[] = $this->name . '@' . ExecutionContext::current();
    }
}

final class FlakyJob implements Job
{
    public function __construct(public readonly string $key) {}
    public function retries(): int
    {
        return 2;
    }
    public function backoff(int $attempt): int
    {
        return 0;
    }

    public function handle(): void
    {
        if ((Ledger::$failuresLeft[$this->key] ?? 0) > 0) {
            Ledger::$failuresLeft[$this->key]--;
            throw new \RuntimeException("flaky {$this->key}");
        }
        Ledger::$ran[] = 'flaky:' . $this->key;
    }
}

final class UniqueJob implements Job
{
    public function __construct(public readonly int $id) {}
    public function uniqueKey(): ?string
    {
        return "u:{$this->id}";
    }
    public function handle(): void
    {
        Ledger::$ran[] = "unique:{$this->id}";
    }
}

final class LongJob implements Job
{
    public function timeout(): int
    {
        return 900;
    }
    public function handle(): void {}
}

final class ObjectArgJob implements Job
{
    public function __construct(public readonly object $thing) {}
    public function handle(): void {}
}

final class HiddenArgJob implements Job
{
    private int $stored;
    public function __construct(int $x)
    {
        $this->stored = $x;
    } // $x can't be read back
    public function handle(): void {}
}

final class QueueTest extends TestCase
{
    private JobQueue $jobs;
    private EventDispatcher $events;

    protected function setUp(): void
    {
        Ledger::$ran = [];
        Ledger::$failuresLeft = [];
        $this->events = new EventDispatcher();
        $this->jobs = $this->manager(new ArrayQueue());
        foreach ([RecordJob::class, FlakyJob::class, UniqueJob::class, LongJob::class] as $class) {
            $this->jobs->job($class);
        }
    }

    protected function tearDown(): void
    {
        ExecutionContext::reset();
        DatabaseManager::reset();
    }

    private function manager(Queue $backend): JobQueue
    {
        $m = new JobQueue();
        $m->setBackend(static fn() => $backend);
        $m->setContainer(new \Kestello\Framework\Core\Container(), $this->events);

        return $m;
    }

    private function drain(JobQueue $m, int $max = 50): void
    {
        (new Worker($m, new ArrayStore(), sleep: static fn() => null))->run(JobQueue::QUEUES, stopWhenEmpty: true, maxJobs: $max);
    }

    /** @return array<string, Connection> sqlite plus KESTELLO_TEST_DATABASES, each with fresh queue tables */
    private function connections(): array
    {
        $connections = ['sqlite' => Connection::sqliteMemory()];
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($extra) ? $extra : [] as $config) {
            $c = new Connection($config);
            $connections[$c->driver()] = $c;
        }
        $migration = require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_04_000006_create_jobs_tables.php';
        foreach ($connections as $c) {
            DatabaseManager::setConnection($c);
            $c->schema()->dropIfExists('failed_jobs');
            $c->schema()->dropIfExists('jobs');
            $migration->up();
        }

        return $connections;
    }

    public function testPayloadsAreJsonAndJobsRunWithInjectedDependencies(): void
    {
        $backend = new ArrayQueue();
        $m = $this->manager($backend);
        $m->job(RecordJob::class);

        $id = $m->push(new RecordJob('a', ['x' => 1]), queue: 'mail');
        $this->assertNotNull($id);
        $payload = Json::decode($backend->jobs[(int) $id]['payload']);
        $this->assertSame([RecordJob::class, ['name' => 'a', 'tags' => ['x' => 1]], 'core'], [$payload['job'], $payload['args'], $payload['pushed_by']]);
        $this->assertSame(36, strlen($payload['uuid']));
        $this->assertTrue(!str_contains($backend->jobs[(int) $id]['payload'], 'O:'), 'never PHP-serialized');

        $this->drain($m);
        $this->assertSame(['a@core'], Ledger::$ran);
        $this->assertSame([], $backend->jobs, 'done jobs are deleted');
    }

    public function testOnlyRegisteredClassesRunAndAsTheirOwner(): void
    {
        $backend = new ArrayQueue();
        $m = $this->manager($backend);
        ExecutionContext::run('plugin:acme', fn() => $m->job(RecordJob::class));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->job(RecordJob::class), 'another owner cannot take it over');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->push(new UniqueJob(1)), 'unregistered push');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->job(\stdClass::class));

        $m->push(new RecordJob('owned'));
        // A tampered row: a registered class name can't change the owner, an unregistered one never runs.
        $backend->push('default', Json::encode(['job' => RecordJob::class, 'args' => ['name' => 'forged'], 'pushed_by' => 'core']));
        $backend->push('default', Json::encode(['job' => \Kestello\Framework\Core\Container::class, 'args' => []]));
        $backend->push('default', 'not json');
        $failed = [];
        $this->events->on(JobFailed::class, function (JobFailed $e) use (&$failed) {
            $failed[] = $e->job;
        });

        $this->drain($m);
        $this->assertSame(['owned@plugin:acme', 'forged@plugin:acme'], Ledger::$ran);
        $this->assertSame([\Kestello\Framework\Core\Container::class, '?'], $failed);
    }

    public function testArgumentsMustBePlainData(): void
    {
        $this->jobs->job(ObjectArgJob::class);
        $this->jobs->job(HiddenArgJob::class);
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->jobs->push(new ObjectArgJob(new \stdClass())));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->jobs->push(new HiddenArgJob(1)));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->jobs->push(new RecordJob('x'), queue: 'Bad Queue'));
    }

    public function testRetriesWithBackoffThenFailedJobs(): void
    {
        $db = Connection::sqliteMemory();
        DatabaseManager::setConnection($db);
        (require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_04_000006_create_jobs_tables.php')->up();
        $m = $this->manager(new DatabaseQueue($db));
        $m->job(FlakyJob::class);

        Ledger::$failuresLeft = ['ok' => 2, 'bad' => 5];
        $m->push(new FlakyJob('ok'));
        $m->push(new FlakyJob('bad'));
        $this->drain($m);

        $this->assertSame(['flaky:ok'], Ledger::$ran, 'succeeded on its third (last) attempt');
        $failed = $db->table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertSame([FlakyJob::class, 3, 'core', 'default'], [$failed[0]['job'], (int) $failed[0]['attempts'], $failed[0]['owner'], $failed[0]['queue']]);
        $this->assertContains('flaky bad', $failed[0]['exception']);
        $this->assertSame(0, $db->table('jobs')->count());

        $this->assertSame(10, JobQueue::defaultBackoff(1));
        $this->assertSame(40, JobQueue::defaultBackoff(3));
        $this->assertSame(3600, JobQueue::defaultBackoff(20));
    }

    public function testUniqueJobsAreSkippedWhilePending(): void
    {
        foreach ($this->connections() as $name => $c) {
            DatabaseManager::setConnection($c);
            $m = $this->manager(new DatabaseQueue($c));
            $m->job(UniqueJob::class);
            Ledger::$ran = [];

            $this->assertNotNull($m->push(new UniqueJob(7)), $name);
            $this->assertNull($m->push(new UniqueJob(7)), "{$name}: duplicate dropped");
            $this->assertNotNull($m->push(new UniqueJob(8)), $name);
            $this->drain($m);
            $this->assertSame(['unique:7', 'unique:8'], Ledger::$ran, $name);
            $this->assertNotNull($m->push(new UniqueJob(7)), "{$name}: allowed again once done");
        }
    }

    public function testDatabaseDriverOnEveryDialect(): void
    {
        foreach ($this->connections() as $name => $c) {
            $q = new DatabaseQueue($c);
            $q->push('low', 'L');
            $q->push('high', 'H');
            $q->push('default', 'later', delay: 3600);

            $stats = $q->stats();
            $this->assertSame([1, 0], [$stats['high']['pending'], $stats['high']['delayed']], $name);
            $this->assertSame(1, $stats['default']['delayed'], $name);

            $first = $q->reserve(['high', 'default', 'low'], 60);
            $this->assertSame(['H', 1], [$first->payload, $first->attempts], "{$name}: priority order");
            $second = $q->reserve(['high', 'default', 'low'], 60);
            $this->assertSame('L', $second->payload, "{$name}: reserved job is hidden, delayed one not available");
            $this->assertNull($q->reserve(['high', 'default', 'low'], 60), $name);

            // A second worker can't steal or finish someone else's job.
            $q->release($second, 0);
            $again = $q->reserve(['low'], 60);
            $this->assertSame(2, $again->attempts, "{$name}: attempts counted per reservation");
            $q->delete($second); // stale reservation token: no effect
            $this->assertSame(1, $q->stats()['low']['reserved'], $name);

            // Expired reservation (crashed worker) becomes available again.
            $c->table('jobs')->where('id', $again->id)->update(['reserved_until' => time() - 1]);
            $rescued = $q->reserve(['low'], 60);
            $this->assertSame([$again->id, 3], [$rescued->id, $rescued->attempts], "{$name}: rescued");
            $q->touch($rescued, 600);
            $this->assertTrue((int) $c->table('jobs')->where('id', $rescued->id)->value('reserved_until') > time() + 500, $name);

            $q->delete($first);
            $q->delete($rescued);
            $this->assertSame(1, $q->clear(), "{$name}: only the delayed one left");
        }
    }

    public function testRedisDriver(): void
    {
        $url = (string) getenv('KESTELLO_TEST_REDIS');
        if ($url === '') {
            $this->assertTrue(true, 'skipped: KESTELLO_TEST_REDIS not set');
            return;
        }
        $redis = \Kestello\Framework\Redis\RedisClient::fromConfig(['url' => $url]);
        $prefix = 'kst-test:q' . bin2hex(random_bytes(3)) . ':';
        $q = new \Kestello\Framework\Queue\RedisQueue($redis, $prefix);

        $q->push('low', 'L');
        $q->push('high', 'H');
        $q->push('default', 'later', delay: 3600);
        $stats = $q->stats();
        $this->assertSame([1, 0], [$stats['high']['pending'], $stats['high']['delayed']]);
        $this->assertSame(1, $stats['default']['delayed']);
        $this->assertNotNull($stats['low']['oldest']);

        $first = $q->reserve(['high', 'default', 'low'], 60);
        $this->assertSame(['H', 1], [$first->payload, $first->attempts], 'priority order');
        $second = $q->reserve(['high', 'default', 'low'], 60);
        $this->assertSame('L', $second->payload, 'reserved job hidden, delayed one not available');
        $this->assertNull($q->reserve(['high', 'default', 'low'], 60));

        $q->release($second, 0);
        $again = $q->reserve(['low'], 60);
        $this->assertSame(2, $again->attempts, 'attempts counted per reservation');
        $q->delete($second); // stale reservation token: no effect
        $this->assertSame(1, $q->stats()['low']['reserved']);

        // A crashed worker: its reservation expires and the job comes back first, attempts kept.
        $redis->command('ZADD', $prefix . 'r:low', time() - 5, $again->id);
        $rescued = $q->reserve(['low'], 60);
        $this->assertSame([$again->id, 3], [$rescued->id, $rescued->attempts], 'rescued');
        $q->touch($rescued, 600);
        $this->assertTrue((int) $redis->command('ZSCORE', $prefix . 'r:low', $rescued->id) > time() + 500);

        // Release with a delay parks it in the delayed set.
        $q->release($rescued, 120);
        $this->assertSame(1, $q->stats()['low']['delayed']);

        $q->delete($first);
        $this->assertSame(2, $q->clear(), 'the delayed default job and the delayed low job');
        $this->assertSame([], $q->stats());

        // Through $cms->queue: unique jobs, retries and the worker loop.
        Ledger::$ran = [];
        $m = $this->manager($q);
        $m->job(UniqueJob::class);
        $m->job(RecordJob::class);
        $this->assertNotNull($m->push(new UniqueJob(7)));
        $this->assertNull($m->push(new UniqueJob(7)), 'duplicate dropped');
        $m->push(new RecordJob('r'));
        $this->drain($m);
        $this->assertSame(['unique:7', 'r@core'], Ledger::$ran);
        $this->assertNotNull($m->push(new UniqueJob(7)), 'allowed again once done');
        $q->clear();
        foreach ($redis->scan($prefix . '*') as $key) {
            $redis->command('DEL', $key);
        }
    }

    public function testCrashedWorkerJobsFailAfterTheirAttempts(): void
    {
        $backend = new ArrayQueue();
        $m = $this->manager($backend);
        $m->job(FlakyJob::class);
        $m->push(new FlakyJob('crashy'));
        $backend->jobs[1]['attempts'] = 3; // three workers already died holding it; this is attempt 4 of 3

        $this->drain($m);
        $this->assertSame([], Ledger::$ran, 'not run a fourth time');
        $this->assertSame([], $backend->jobs);
    }

    public function testLongJobsAreHeldForTheirTimeout(): void
    {
        $backend = new ArrayQueue();
        $m = $this->manager($backend);
        $m->job(LongJob::class);
        $m->push(new LongJob());
        $this->assertSame(930, $m->visibilityFor($backend->jobs[1]['payload']));
    }

    public function testSyncDriverRunsImmediatelyAndRecordsFailures(): void
    {
        $m = $this->manager(new SyncQueue());
        $m->job(RecordJob::class);
        $m->job(FlakyJob::class);
        $this->assertNull($m->push(new RecordJob('now')));
        $this->assertSame(['now@core'], Ledger::$ran);

        Ledger::$failuresLeft = ['s' => 1];
        $this->assertThrows(\RuntimeException::class, fn() => $m->push(new FlakyJob('s')), 'sync surfaces errors');
    }

    public function testWorkerStopsOnRestartSignal(): void
    {
        $cache = new ArrayStore();
        $m = $this->manager(new ArrayQueue());
        $m->job(RecordJob::class);
        $polls = 0;
        $worker = new Worker($m, $cache, sleep: function () use ($cache, &$polls): void {
            if (++$polls === 2) {
                Worker::requestRestart($cache);
            }
        });
        $this->assertSame('restart requested', $worker->run(['default']));
        $this->assertTrue(is_array($cache->get(Worker::HEARTBEAT_KEY)), 'heartbeat recorded');

        $m->push(new RecordJob('a'));
        $m->push(new RecordJob('b'));
        $this->assertSame('max jobs', (new Worker($m, new ArrayStore()))->run(['default'], maxJobs: 1));
        $this->assertSame(['a@core'], Ledger::$ran);
    }

    public function testSecretsAreMaskedForDisplay(): void
    {
        $shown = JobQueue::displayPayload(Json::encode(['job' => 'X', 'args' => ['api_token' => 'abc', 'count' => 3, 'nested' => ['password' => 'p']]]));
        $this->assertSame(['api_token' => '[redacted]', 'count' => 3, 'nested' => ['password' => '[redacted]']], $shown['args']);
    }
}
