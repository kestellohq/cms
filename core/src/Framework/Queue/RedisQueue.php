<?php

declare(strict_types=1);

namespace Kestello\Framework\Queue;

use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;
use Kestello\Framework\Redis\RedisClient;

/**
 * Jobs in Redis / Valkey. Per queue:
 *
 *   <p>q:<queue>   list     ids ready to run (FIFO)
 *   <p>d:<queue>   zset     delayed ids, scored by when they become available
 *   <p>r:<queue>   zset     reserved ids, scored by when the reservation expires
 *   <p>j:<id>      hash     payload, attempts, reservation token, created_at, unique key
 *   <p>u:<key>     string   unique key => id while that job exists
 *   <p>queues      set      queue names (for stats and clear)
 *
 * Every state change is one Lua script, so two workers can never take the
 * same job. Reserving first moves due delayed jobs and expired reservations
 * (a crashed worker's job) back to the ready list; a recovered job goes to
 * the front, keeping its attempt count. Standalone Redis only: the scripts
 * build key names from the prefix, which Redis Cluster does not allow.
 */
final class RedisQueue implements Queue
{
    private const string PUSH = <<<'LUA'
        local p, queue, payload, delay, unique, now = ARGV[1], ARGV[2], ARGV[3], tonumber(ARGV[4]), ARGV[5], tonumber(ARGV[6])
        local id = tostring(redis.call('INCR', p .. 'seq'))
        if unique ~= '' then
            if redis.call('SET', p .. 'u:' .. unique, id, 'NX') == false then return false end
        end
        redis.call('HSET', p .. 'j:' .. id, 'queue', queue, 'payload', payload, 'attempts', 0, 'created_at', now, 'unique', unique)
        redis.call('SADD', p .. 'queues', queue)
        if delay > 0 then
            redis.call('ZADD', p .. 'd:' .. queue, now + delay, id)
        else
            redis.call('RPUSH', p .. 'q:' .. queue, id)
        end
        return id
        LUA;

    private const string RESERVE = <<<'LUA'
        local p, queue, now, until_, token = ARGV[1], ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4]), ARGV[5]
        local q, d, r = p .. 'q:' .. queue, p .. 'd:' .. queue, p .. 'r:' .. queue
        for _, id in ipairs(redis.call('ZRANGEBYSCORE', d, '-inf', now, 'LIMIT', 0, 100)) do
            redis.call('ZREM', d, id)
            redis.call('RPUSH', q, id)
        end
        for _, id in ipairs(redis.call('ZRANGEBYSCORE', r, '-inf', now - 1, 'LIMIT', 0, 100)) do
            redis.call('ZREM', r, id)
            redis.call('LPUSH', q, id)
        end
        while true do
            local id = redis.call('LPOP', q)
            if not id then return false end
            local key = p .. 'j:' .. id
            if redis.call('EXISTS', key) == 1 then
                local attempts = redis.call('HINCRBY', key, 'attempts', 1)
                redis.call('HSET', key, 'reservation', token)
                redis.call('ZADD', r, until_, id)
                return {id, redis.call('HGET', key, 'payload'), attempts}
            end
        end
        LUA;

    /** touch / delete / release: only the holder of the reservation may act. */
    private const string SETTLE = <<<'LUA'
        local p, queue, id, token, action, at = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5], tonumber(ARGV[6])
        local key = p .. 'j:' .. id
        if redis.call('HGET', key, 'reservation') ~= token then return 0 end
        local r = p .. 'r:' .. queue
        if action == 'touch' then
            redis.call('ZADD', r, 'XX', at, id)
            return 1
        end
        redis.call('ZREM', r, id)
        if action == 'delete' then
            local unique = redis.call('HGET', key, 'unique')
            if unique and unique ~= '' and redis.call('GET', p .. 'u:' .. unique) == id then
                redis.call('DEL', p .. 'u:' .. unique)
            end
            redis.call('DEL', key)
            return 1
        end
        redis.call('HDEL', key, 'reservation')
        if action == 'refund' and tonumber(redis.call('HGET', key, 'attempts')) > 0 then
            redis.call('HINCRBY', key, 'attempts', -1)
        end
        if at > tonumber(ARGV[7]) then
            redis.call('ZADD', p .. 'd:' .. queue, at, id)
        else
            redis.call('RPUSH', p .. 'q:' .. queue, id)
        end
        return 1
        LUA;

    private const string CLEAR = <<<'LUA'
        local p, queue = ARGV[1], ARGV[2]
        local ids = redis.call('LRANGE', p .. 'q:' .. queue, 0, -1)
        for _, id in ipairs(redis.call('ZRANGE', p .. 'd:' .. queue, 0, -1)) do table.insert(ids, id) end
        for _, id in ipairs(redis.call('ZRANGE', p .. 'r:' .. queue, 0, -1)) do table.insert(ids, id) end
        local n = 0
        for _, id in ipairs(ids) do
            local key = p .. 'j:' .. id
            local unique = redis.call('HGET', key, 'unique')
            if unique and unique ~= '' then redis.call('DEL', p .. 'u:' .. unique) end
            n = n + redis.call('DEL', key)
        end
        redis.call('DEL', p .. 'q:' .. queue, p .. 'd:' .. queue, p .. 'r:' .. queue)
        redis.call('SREM', p .. 'queues', queue)
        return n
        LUA;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix = 'kst:queue:',
    ) {}

    public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string
    {
        $id = $this->redis->eval(self::PUSH, [], [$this->prefix, $queue, $payload, max(0, $delay), $uniqueKey ?? '', time()]);

        return $id === null || $id === false ? null : (string) $id;
    }

    public function reserve(array $queues, int $visibility): ?ReservedJob
    {
        $now = time();
        foreach ($queues as $queue) {
            $token = bin2hex(random_bytes(16));
            $row = $this->redis->eval(self::RESERVE, [], [$this->prefix, $queue, $now, $now + max(1, $visibility), $token]);
            if (is_array($row)) {
                return new ReservedJob((string) $row[0], $queue, (string) $row[1], (int) $row[2], $token);
            }
        }

        return null;
    }

    public function touch(ReservedJob $job, int $visibility): void
    {
        $this->settle($job, 'touch', time() + max(1, $visibility));
    }

    public function delete(ReservedJob $job): void
    {
        $this->settle($job, 'delete', 0);
    }

    public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void
    {
        $this->settle($job, $refund ? 'refund' : 'release', time() + max(0, $delay));
    }

    public function stats(): array
    {
        $now = time();
        $out = [];
        $queues = (array) $this->redis->command('SMEMBERS', $this->prefix . 'queues');
        sort($queues);
        foreach ($queues as $queue) {
            [$ready, $due, $delayed, $expired, $reserved, $head] = $this->redis->pipeline([
                ['LLEN', $this->prefix . 'q:' . $queue],
                ['ZCOUNT', $this->prefix . 'd:' . $queue, '-inf', $now],
                ['ZCOUNT', $this->prefix . 'd:' . $queue, '(' . $now, '+inf'],
                ['ZCOUNT', $this->prefix . 'r:' . $queue, '-inf', '(' . $now],
                ['ZCOUNT', $this->prefix . 'r:' . $queue, $now, '+inf'],
                ['LINDEX', $this->prefix . 'q:' . $queue, 0],
            ]);
            $oldest = $head !== null && !$head instanceof \Throwable ? $this->redis->command('HGET', $this->prefix . 'j:' . $head, 'created_at') : null;
            $pending = (int) $ready + (int) $due + (int) $expired;
            if ($pending + (int) $delayed + (int) $reserved === 0) {
                continue;
            }
            $out[(string) $queue] = ['pending' => $pending, 'delayed' => (int) $delayed, 'reserved' => (int) $reserved, 'oldest' => $oldest !== null ? (int) $oldest : null];
        }

        return $out;
    }

    public function clear(?string $queue = null): int
    {
        $queues = $queue !== null ? [$queue] : (array) $this->redis->command('SMEMBERS', $this->prefix . 'queues');
        $n = 0;
        foreach ($queues as $q) {
            $n += (int) $this->redis->eval(self::CLEAR, [], [$this->prefix, (string) $q]);
        }

        return $n;
    }

    private function settle(ReservedJob $job, string $action, int $at): void
    {
        $this->redis->eval(self::SETTLE, [], [$this->prefix, $job->queue, $job->id, $job->reservation, $action, $at, time()]);
    }
}
