<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

use Kestello\Framework\Database\Connection;

/**
 * Cache in the `cache` table (key, value, expiration). Works on every
 * supported database; good for small multi-server sites without Redis.
 */
final class DatabaseStore extends AbstractStore implements Prunable
{
    public function __construct(
        private readonly Connection $connection,
        string $prefix = '',
        private readonly string $table = 'cache',
    ) {
        parent::__construct($prefix);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->query()->where('key', $this->dbKey($key))->first();
        if ($row === null) {
            return $default;
        }
        if (self::expired((int) $row['expiration'])) {
            $this->forget($key);
            return $default;
        }

        return self::decode((string) $row['value']);
    }

    public function many(array $keys): array
    {
        $out = array_fill_keys($keys, null);
        $map = [];
        foreach ($keys as $key) {
            $map[$this->dbKey($key)] = $key;
        }
        foreach ($this->query()->whereIn('key', array_keys($map))->get() as $row) {
            if (!self::expired((int) $row['expiration'])) {
                $out[$map[$row['key']]] = self::decode((string) $row['value']);
            }
        }

        return $out;
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->query()->upsert(
            ['key' => $this->dbKey($key), 'value' => self::encode($value), 'expiration' => self::expiresAt($ttl)],
            ['key'],
            ['value', 'expiration'],
        );

        return true;
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $dbKey = $this->dbKey($key);
        $encoded = self::encode($value);

        // Clear an expired row first, then insert-if-absent (no exceptions,
        // so it is safe inside an outer Postgres transaction).
        $this->query()->where('key', $dbKey)->where('expiration', '>', 0)->where('expiration', '<=', time())->delete();

        return $this->query()->upsert(['key' => $dbKey, 'value' => $encoded, 'expiration' => self::expiresAt($ttl)], ['key'], []) === 1;
    }

    public function increment(string $key, int $by = 1): int
    {
        $dbKey = $this->dbKey($key);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $this->connection->transaction(function () use ($dbKey, $by): ?int {
                $row = $this->query()->where('key', $dbKey)->lockForUpdate()->first();

                if ($row === null || self::expired((int) $row['expiration'])) {
                    if ($row !== null) {
                        $this->query()->where('key', $dbKey)->delete();
                    }
                    $inserted = $this->query()->upsert(['key' => $dbKey, 'value' => (string) $by, 'expiration' => 0], ['key'], []);

                    return $inserted === 1 ? $by : null; // lost a race: retry
                }

                $value = (int) self::decode((string) $row['value']) + $by;
                $this->query()->where('key', $dbKey)->update(['value' => (string) $value]);

                return $value;
            });

            if ($result !== null) {
                return $result;
            }
        }

        throw new \RuntimeException("Could not increment cache key \"{$key}\" (contention).");
    }

    public function forget(string $key): bool
    {
        $this->query()->where('key', $this->dbKey($key))->delete();

        return true;
    }

    public function forgetIfValue(string $key, mixed $value): bool
    {
        return $this->query()->where('key', $this->dbKey($key))->where('value', self::encode($value))->delete() > 0;
    }

    public function flush(): bool
    {
        $query = $this->query();
        if ($this->prefix !== '') {
            $query->where('key', 'LIKE', \Kestello\Framework\Database\QueryBuilder::escapeLike($this->prefix) . '%');
        }
        $query->delete();

        return true;
    }

    /** Delete expired rows (scheduler). */
    public function prune(): int
    {
        return $this->query()->where('expiration', '>', 0)->where('expiration', '<=', time())->delete();
    }

    private function query(): \Kestello\Framework\Database\QueryBuilder
    {
        return $this->connection->table($this->table);
    }

    /**
     * Keys longer than the column are hashed (prefix kept for flush()), and
     * so are keys MySQL's collations would mix up with others: "Report"
     * and "report", "a" and "a " are one key there, two everywhere else.
     */
    private function dbKey(string $key): string
    {
        $full = $this->key($key);
        $exact = strlen($full) <= 191 && preg_match('/[A-Z]|[^\x00-\x7F]|\s$/', $key) !== 1;

        return $exact ? $full : $this->prefix . 'h:' . hash('sha256', $key);
    }

    private static function expired(int $expiration): bool
    {
        return $expiration !== 0 && $expiration <= time();
    }
}
