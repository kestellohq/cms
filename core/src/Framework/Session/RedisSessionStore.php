<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

use Kestello\Framework\Redis\RedisClient;

/**
 * Sessions in Redis. Each session is a JSON record with its payload and
 * metadata under <prefix>sess:<hash>, expiring after the lifetime; a set
 * <prefix>sess-user:<id> indexes a user's sessions for the device list.
 */
final class RedisSessionStore extends AbstractSessionStore
{
    public function __construct(private readonly RedisClient $redis, private readonly string $prefix, int $lifetime = 7200)
    {
        parent::__construct($lifetime);
    }

    public function name(): string
    {
        return 'redis';
    }

    public function read(string $id): string|false
    {
        $record = $this->record(self::hash($id));

        return $record['payload'] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        $hash = self::hash($id);
        $meta = $this->metadata();
        $existing = $this->record($hash);
        $now = time();

        $record = [
            'payload' => $data,
            'user_id' => $meta->userId,
            'ip' => self::clip($meta->ip, 45),
            'user_agent' => self::clip($meta->userAgent, 500),
            'created_at' => $existing['created_at'] ?? $now,
            'last_activity' => $now,
        ];

        $commands = [['SET', $this->key($hash), json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 'EX', $this->lifetime]];
        if (($existing['user_id'] ?? null) !== null && $existing['user_id'] !== $meta->userId) {
            $commands[] = ['SREM', $this->userKey((int) $existing['user_id']), $hash];
        }
        if ($meta->userId !== null) {
            $commands[] = ['SADD', $this->userKey($meta->userId), $hash];
            $commands[] = ['EXPIRE', $this->userKey($meta->userId), $this->lifetime];
        }

        foreach ($this->redis->pipeline($commands) as $reply) {
            if ($reply instanceof \Throwable) {
                return false;
            }
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        return $this->destroyHashed(self::hash($id));
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0; // Redis expires keys itself
    }

    public function validateId(string $id): bool
    {
        return (int) $this->redis->command('EXISTS', $this->key(self::hash($id))) === 1;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->redis->command('EXPIRE', $this->key(self::hash($id)), $this->lifetime);

        return true;
    }

    public function forUser(int $userId): array
    {
        $hashes = (array) $this->redis->command('SMEMBERS', $this->userKey($userId));
        $out = [];
        $stale = [];

        foreach ($hashes as $hash) {
            $record = $this->record((string) $hash);
            if ($record === null || ($record['user_id'] ?? null) !== $userId) {
                $stale[] = (string) $hash;
                continue;
            }
            $out[] = [
                'id' => (string) $hash,
                'user_id' => $userId,
                'ip' => (string) $record['ip'],
                'user_agent' => (string) $record['user_agent'],
                'created_at' => (int) $record['created_at'],
                'last_activity' => (int) $record['last_activity'],
            ];
        }
        if ($stale !== []) {
            $this->redis->command('SREM', $this->userKey($userId), ...$stale);
        }

        usort($out, static fn($a, $b) => $b['last_activity'] <=> $a['last_activity']);

        return $out;
    }

    public function destroyForUser(int $userId, ?string $exceptHashedId = null): int
    {
        $count = 0;
        foreach ($this->forUser($userId) as $session) {
            if ($session['id'] !== $exceptHashedId) {
                $this->destroyHashed($session['id']);
                $count++;
            }
        }

        return $count;
    }

    public function destroyHashed(string $hashedId): bool
    {
        $record = $this->record($hashedId);
        $commands = [['DEL', $this->key($hashedId)]];
        if (($record['user_id'] ?? null) !== null) {
            $commands[] = ['SREM', $this->userKey((int) $record['user_id']), $hashedId];
        }
        $this->redis->pipeline($commands);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function record(string $hash): ?array
    {
        $raw = $this->redis->command('GET', $this->key($hash));
        $record = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($record) ? $record : null;
    }

    private function key(string $hash): string
    {
        return $this->prefix . 'sess:' . $hash;
    }

    private function userKey(int $userId): string
    {
        return $this->prefix . 'sess-user:' . $userId;
    }
}
