<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use InvalidArgumentException;
use Kestello\Contracts\Cache\LockTimeout;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Cache\ApcuStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Cache\DatabaseStore;
use Kestello\Framework\Cache\FailoverStore;
use Kestello\Framework\Cache\FileStore;
use Kestello\Framework\Cache\RedisStore;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Redis\RedisClient;
use Kestello\Framework\Redis\RedisException;
use Kestello\Framework\Testing\TestCase;

/**
 * The same contract tests on every cache store. Redis runs when
 * KESTELLO_TEST_REDIS is set (a redis:// URL; keys use a test prefix),
 * APCu when apc.enable_cli=1, extra databases via KESTELLO_TEST_DATABASES.
 */
final class CacheTest extends TestCase
{
    /** @return array<string, Store> */
    private function stores(): array
    {
        $root = $this->tempDir();
        $stores = [
            'array' => new ArrayStore('t:'),
            'file' => new FileStore($root . '/cache', 't:'),
        ];

        foreach (self::databases() as $name => $connection) {
            $stores["database@{$name}"] = new DatabaseStore($connection, 'kst-test:' . bin2hex(random_bytes(3)) . ':');
        }

        if (($url = (string) getenv('KESTELLO_TEST_REDIS')) !== '') {
            $stores['redis'] = new RedisStore(RedisClient::fromConfig(['url' => $url]), 'kst-test:' . bin2hex(random_bytes(3)) . ':');
        }

        if (ApcuStore::available()) {
            $stores['apcu'] = new ApcuStore('kst-test:' . bin2hex(random_bytes(3)) . ':');
        }

        return $stores;
    }

    /** @return array<string, Connection> connections with the cache table migrated */
    private static function databases(): array
    {
        static $connections = null;
        if ($connections !== null) {
            return $connections;
        }

        $connections = ['sqlite' => Connection::sqliteMemory()];
        foreach (json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true) ?: [] as $config) {
            $c = new Connection($config);
            $connections[$c->driver()] = $c;
        }

        foreach ($connections as $c) {
            if (!$c->schema()->hasTable('cache')) {
                $c->schema()->create('cache', function (\Kestello\Framework\Database\Blueprint $t): void {
                    $t->string('key', 191)->primary();
                    $t->longText('value');
                    $t->bigInteger('expiration');
                });
            }
        }

        return $connections;
    }

    /** @param callable(Store, string): void $test */
    private function each(callable $test): void
    {
        foreach ($this->stores() as $name => $store) {
            try {
                $test($store, $name);
            } finally {
                $store->flush();
            }
        }
    }

    public function testValuesRoundTripWithTypes(): void
    {
        $this->each(function (Store $s, string $name): void {
            $values = ['int' => 42, 'neg' => -7, 'float' => 1.5, 'str' => 'hello', 'numeric-string' => '123', 'true' => true,
                'false' => false, 'null' => null, 'list' => [1, 'a', [true]], 'map' => ['a' => ['b' => 1]], 'empty' => ''];

            foreach ($values as $key => $value) {
                $this->assertTrue($s->put($key, $value), "{$name}: put {$key}");
                $this->assertSame($value, $s->get($key, 'default'), "{$name}: {$key}");
            }

            $this->assertSame('default', $s->get('missing', 'default'), $name);
            $this->assertTrue($s->has('false') && $s->has('int'), "{$name}: has() true for falsy values");
            $this->assertFalse($s->has('missing'), $name);
            $this->assertSame(['int' => 42, 'missing' => null, 'str' => 'hello'], $s->many(['int', 'missing', 'str']), "{$name}: many");

            $s->putMany(['m1' => 'a', 'm2' => 'b']);
            $this->assertSame('a', $s->pull('m1'), $name);
            $this->assertFalse($s->has('m1'), "{$name}: pulled");
            $this->assertTrue($s->forget('m2') && !$s->has('m2'), $name);
        });
    }

    public function testKeysAreExactOnEveryStore(): void
    {
        $this->each(function (Store $s, string $name): void {
            $s->putMany(['report' => 1, 'Report' => 2, 'a' => 3, 'a ' => 4, 'café' => 5, 'cafe' => 6]);
            $this->assertSame(['report' => 1, 'Report' => 2, 'a' => 3, 'a ' => 4, 'café' => 5, 'cafe' => 6], $s->many(['report', 'Report', 'a', 'a ', 'café', 'cafe']), "{$name}: no two keys share a slot");
        });
    }

    public function testObjectsAreRejected(): void
    {
        $this->each(function (Store $s, string $name): void {
            $this->assertThrows(InvalidArgumentException::class, fn() => $s->put('o', new \stdClass()), $name);
            $this->assertThrows(InvalidArgumentException::class, fn() => $s->put('o', ['nested' => [new \ArrayObject()]]), "{$name}: nested");
        });
    }

    public function testTamperedPayloadCannotInstantiateObjects(): void
    {
        $root = $this->tempDir();
        $store = new FileStore($root);
        $store->put('k', 'x');
        $file = glob($root . '/*/*.cache')[0];
        file_put_contents($file, '0000000000' . serialize(new \ArrayObject([1])));

        $value = $store->get('k');
        $this->assertFalse($value instanceof \ArrayObject, 'no object instantiated from cache data');
    }

    public function testExpiryAndTtlKeptByIncrement(): void
    {
        $stores = $this->stores();
        foreach ($stores as $s) {
            $s->put('soon', 'v', 1);
            $s->put('forever', 'v');
            $s->add('counter', 0, 1);
            $s->increment('counter');
        }

        sleep(2);

        foreach ($stores as $name => $s) {
            $this->assertSame('gone', $s->get('soon', 'gone'), "{$name}: expired");
            $this->assertFalse($s->has('soon'), $name);
            $this->assertSame('v', $s->get('forever'), "{$name}: no TTL");
            $this->assertSame('gone', $s->get('counter', 'gone'), "{$name}: increment kept the TTL");
            $this->assertTrue($s->add('soon', 'again', 60), "{$name}: add over an expired key");
            $s->flush();
        }
    }

    public function testAddIncrementAreAtomic(): void
    {
        $this->each(function (Store $s, string $name): void {
            $this->assertTrue($s->add('a', 1, 60), $name);
            $this->assertFalse($s->add('a', 2, 60), "{$name}: add on existing");
            $this->assertSame(1, $s->get('a'), $name);

            $this->assertSame(1, $s->increment('n'), "{$name}: missing starts at 0");
            $this->assertSame(6, $s->increment('n', 5), $name);
            $this->assertSame(4, $s->decrement('n', 2), $name);
            $this->assertSame(4, $s->get('n'), "{$name}: counter readable");
        });
    }

    public function testRememberAndLocks(): void
    {
        $this->each(function (Store $s, string $name): void {
            $calls = 0;
            $make = function () use (&$calls): array {
                $calls++;
                return ['built' => true];
            };
            $this->assertSame(['built' => true], $s->remember('r', 60, $make), $name);
            $this->assertSame(['built' => true], $s->remember('r', 60, $make), $name);
            $this->assertSame(1, $calls, "{$name}: callback ran once");

            $a = $s->lock('job', 30);
            $b = $s->lock('job', 30);
            $this->assertTrue($a->acquire(), $name);
            $this->assertFalse($b->acquire(), "{$name}: held");
            $this->assertFalse($b->release(), "{$name}: non-owner cannot release");
            $this->assertFalse($b->get(fn() => 'ran'), $name);
            $this->assertThrows(LockTimeout::class, fn() => $b->block(1), $name);

            // Release from another process by passing the owner token.
            $this->assertTrue($s->lock('job', 30, $a->owner())->release(), "{$name}: owner token release");
            $this->assertSame('ran', $b->get(fn() => 'ran'), "{$name}: free again");
            $this->assertTrue($s->lock('job')->acquire(), "{$name}: released after get()");
        });
    }

    public function testFlushOnlyTouchesItsPrefix(): void
    {
        foreach (self::databases() as $name => $c) {
            $mine = new DatabaseStore($c, 'site-a:');
            $other = new DatabaseStore($c, 'site_b:');
            $mine->put('k', 1);
            $other->put('k', 2);
            $mine->flush();
            $this->assertNull($mine->get('k'), $name);
            $this->assertSame(2, $other->get('k'), "{$name}: other prefix untouched (and '_' is not a wildcard)");
            $other->flush();
        }

        if (($url = (string) getenv('KESTELLO_TEST_REDIS')) !== '') {
            $client = RedisClient::fromConfig(['url' => $url]);
            $mine = new RedisStore($client, 'kst-test-a:');
            $other = new RedisStore($client, 'kst-test-b:');
            $mine->put('k', 1);
            $other->put('k', 2);
            $mine->flush();
            $this->assertSame([null, 2], [$mine->get('k'), $other->get('k')], 'redis');
            $other->flush();
            $this->assertThrows(\LogicException::class, fn() => (new RedisStore($client, ''))->flush(), 'no unprefixed flush');
        }
    }

    public function testFailoverUsesFallbackAndTripsBreaker(): void
    {
        $root = $this->tempDir();
        $logs = [];
        $broken = new class extends \Kestello\Framework\Cache\AbstractStore {
            public int $calls = 0;
            private function fail(): never
            {
                $this->calls++;
                throw new RedisException('down');
            }
            public function get(string $key, mixed $default = null): mixed
            {
                $this->fail();
            }
            public function put(string $key, mixed $value, int $ttl = 0): bool
            {
                $this->fail();
            }
            public function add(string $key, mixed $value, int $ttl = 0): bool
            {
                $this->fail();
            }
            public function increment(string $key, int $by = 1): int
            {
                $this->fail();
            }
            public function forget(string $key): bool
            {
                $this->fail();
            }
            public function forgetIfValue(string $key, mixed $value): bool
            {
                $this->fail();
            }
            public function flush(): bool
            {
                $this->fail();
            }
        };
        $store = new FailoverStore(fn() => $broken, new ArrayStore(), $root . '/breaker', 30, function (string $m) use (&$logs) {
            $logs[] = $m;
        });
        $this->assertSame('d', $store->get('k', 'd'));
        $this->assertTrue($store->put('k', 'v'), 'fallback took the write');
        $this->assertSame('v', $store->get('k'));
        $this->assertSame(1, $broken->calls, 'primary skipped after the first failure');
        $this->assertCount(1, $logs);
        $this->assertTrue(is_file($root . '/breaker'));

        // The breaker holds for the next request too.
        $next = new FailoverStore(fn() => $broken, new ArrayStore(), $root . '/breaker', 30);
        $this->assertTrue($next->degraded());
        $next->get('k');
        $this->assertSame(1, $broken->calls);

        // Caller errors are not outages.
        $this->assertThrows(InvalidArgumentException::class, fn() => (new FailoverStore(fn() => new ArrayStore(), new ArrayStore(), $root . '/b2'))->put('o', new \stdClass()));
        $this->assertFalse(is_file($root . '/b2'));
    }

    public function testRateLimiterCountsPerWindow(): void
    {
        $this->each(function (Store $s, string $name): void {
            $limiter = new RateLimiter($s);
            $results = [];
            for ($i = 0; $i < 4; $i++) {
                $results[] = $limiter->hit('login:1.2.3.4', max: 3, window: 60);
            }

            $this->assertSame([true, true, true, false], array_map(fn($r) => $r->allowed, $results), $name);
            $this->assertSame([2, 1, 0, 0], array_map(fn($r) => $r->remaining, $results), $name);
            $this->assertTrue($results[0]->resetIn >= 1 && $results[0]->resetIn <= 60, $name);
            $this->assertSame(4, $limiter->attempts('login:1.2.3.4'), $name);
            $this->assertTrue($limiter->hit('login:5.6.7.8', 3, 60)->allowed, "{$name}: keys are separate");

            $limiter->clear('login:1.2.3.4');
            $this->assertTrue($limiter->hit('login:1.2.3.4', 3, 60)->allowed, "{$name}: cleared");
        });
    }

    public function testRedisClientParsesUrlsAndReportsErrors(): void
    {
        $this->assertThrows(RedisException::class, fn() => RedisClient::fromConfig(['url' => 'http://x']));
        $this->assertThrows(RedisException::class, fn() => (new RedisClient(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]))->ping());

        if (($url = (string) getenv('KESTELLO_TEST_REDIS')) === '') {
            return;
        }
        $redis = RedisClient::fromConfig(['url' => $url]);
        $this->assertTrue($redis->ping());
        $this->assertThrows(RedisException::class, fn() => $redis->command('BOGUSCMD'));
        $replies = $redis->pipeline([['SET', 'kst-test:p', "bin\r\n\0ary"], ['GET', 'kst-test:p'], ['DEL', 'kst-test:p']]);
        $this->assertSame(['OK', "bin\r\n\0ary", 1], $replies, 'binary-safe values');
        $this->assertTrue($redis->ping(), 'connection usable after an error reply');
    }
}
