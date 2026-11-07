<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

use Kestello\Framework\Database\Connection;

/** Sessions in the `sessions` table, with device metadata. The default. */
final class DatabaseSessionStore extends AbstractSessionStore
{
    public function __construct(private readonly Connection $connection, int $lifetime = 7200, private readonly string $table = 'sessions')
    {
        parent::__construct($lifetime);
    }

    public function name(): string
    {
        return 'database';
    }

    public function read(string $id): string|false
    {
        $hash = self::hash($id);
        $row = $this->query()->where('id', $hash)->first();
        if ($row === null || (int) $row['last_activity'] + $this->lifetime < time()) {
            return '';
        }

        return (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        $hash = self::hash($id);
        $meta = $this->metadata();
        $now = time();

        $this->query()->upsert([
            'id' => $hash,
            'user_id' => $meta->userId,
            'ip' => self::clip($meta->ip, 45),
            'user_agent' => self::clip($meta->userAgent, 500),
            'payload' => $data,
            'last_activity' => $now,
            'created_at' => $now,
        ], ['id'], ['user_id', 'ip', 'user_agent', 'payload', 'last_activity']);
        return true;
    }

    public function destroy(string $id): bool
    {
        return $this->destroyHashed(self::hash($id));
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->query()->where('last_activity', '<', time() - $max_lifetime)->delete();
    }

    public function validateId(string $id): bool
    {
        $row = $this->query()->select('last_activity')->where('id', self::hash($id))->first();

        return $row !== null && (int) $row['last_activity'] + $this->lifetime >= time();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->query()->where('id', self::hash($id))->where('last_activity', '<', time() - self::TOUCH_INTERVAL)
            ->update(['last_activity' => time()]);

        return true;
    }

    public function forUser(int $userId): array
    {
        $rows = $this->query()->select('id', 'user_id', 'ip', 'user_agent', 'created_at', 'last_activity')
            ->where('user_id', $userId)->where('last_activity', '>=', time() - $this->lifetime)
            ->orderByDesc('last_activity')->get();

        return array_map(static fn(array $r) => [
            'id' => (string) $r['id'],
            'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'ip' => (string) $r['ip'],
            'user_agent' => (string) $r['user_agent'],
            'created_at' => (int) $r['created_at'],
            'last_activity' => (int) $r['last_activity'],
        ], $rows);
    }

    public function destroyForUser(int $userId, ?string $exceptHashedId = null): int
    {
        $query = $this->query()->where('user_id', $userId);
        if ($exceptHashedId !== null) {
            $query->where('id', '!=', $exceptHashedId);
        }

        return $query->delete();
    }

    public function destroyHashed(string $hashedId): bool
    {
        $this->query()->where('id', $hashedId)->delete();

        return true;
    }

    private function query(): \Kestello\Framework\Database\QueryBuilder
    {
        return $this->connection->table($this->table);
    }
}
