<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

use Kestello\Framework\Redis\RedisClient;

/** Cache in Redis (or Valkey, KeyDB, Dragonfly, Upstash…). */
final class RedisStore extends AbstractStore
{
    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

    public function __construct(private readonly RedisClient $redis, string $prefix = '')
    {
        parent::__construct($prefix);
    }

    public function client(): RedisClient
    {
        return $this->redis;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->command('GET', $this->key($key));

        return $raw === null ? $default : self::decode((string) $raw);
    }

    public function many(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $values = $this->redis->command('MGET', ...array_map(fn(string $k) => $this->key($k), $keys));
        $out = [];
        foreach (array_values($keys) as $i => $key) {
            $out[$key] = ($values[$i] ?? null) === null ? null : self::decode((string) $values[$i]);
        }

        return $out;
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $args = ['SET', $this->key($key), self::encode($value)];
        if ($ttl > 0) {
            array_push($args, 'EX', $ttl);
        }

        return $this->redis->command(...$args) === 'OK';
    }

    public function putMany(array $values, int $ttl = 0): bool
    {
        $commands = [];
        foreach ($values as $key => $value) {
            $commands[] = $ttl > 0
                ? ['SET', $this->key((string) $key), self::encode($value), 'EX', $ttl]
                : ['SET', $this->key((string) $key), self::encode($value)];
        }

        return !in_array(false, array_map(static fn($r) => $r === 'OK', $this->redis->pipeline($commands)), true);
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $args = ['SET', $this->key($key), self::encode($value), 'NX'];
        if ($ttl > 0) {
            array_push($args, 'EX', $ttl);
        }

        return $this->redis->command(...$args) === 'OK';
    }

    public function increment(string $key, int $by = 1): int
    {
        return (int) $this->redis->command('INCRBY', $this->key($key), $by);
    }

    public function forget(string $key): bool
    {
        $this->redis->command('DEL', $this->key($key));

        return true;
    }

    public function forgetIfValue(string $key, mixed $value): bool
    {
        return (int) $this->redis->eval(self::RELEASE_SCRIPT, [$this->key($key)], [self::encode($value)]) === 1;
    }

    /** Deletes this prefix only (SCAN + UNLINK), never FLUSHDB. */
    public function flush(): bool
    {
        if ($this->prefix === '') {
            throw new \LogicException('Refusing to flush a Redis cache without a key prefix.');
        }

        $batch = [];
        foreach ($this->redis->scan(addcslashes($this->prefix, '*?[]\\') . '*') as $key) {
            $batch[] = $key;
            if (count($batch) === 500) {
                $this->redis->command('UNLINK', ...$batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->redis->command('UNLINK', ...$batch);
        }

        return true;
    }
}
