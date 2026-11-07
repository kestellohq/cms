<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

use Closure;
use InvalidArgumentException;
use Kestello\Contracts\Cache\Lock;
use Kestello\Contracts\Cache\Store;

/**
 * Shared behaviour: value encoding, remember/pull, many/putMany and locks
 * built on add() + forgetIfValue().
 *
 * Encoding: integers are stored as plain digits (so Redis INCRBY and friends
 * work on them); everything else is serialize()d and read back with
 * allowed_classes = false, so a tampered cache can't instantiate objects.
 */
abstract class AbstractStore implements Store
{
    protected const string LOCK_PREFIX = 'lock:';

    public function __construct(protected readonly string $prefix = '') {}

    /** Delete $key only if it currently holds $value (for lock release). */
    abstract public function forgetIfValue(string $key, mixed $value): bool;

    public function many(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function putMany(array $values, int $ttl = 0): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->put((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $missing = new \stdClass();
        $value = $this->get($key, $missing);
        if ($value !== $missing) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $missing = new \stdClass();
        $value = $this->get($key, $missing);
        if ($value === $missing) {
            return $default;
        }
        $this->forget($key);

        return $value;
    }

    public function lock(string $name, int $seconds = 10, ?string $owner = null): Lock
    {
        return new CacheLock($this, self::LOCK_PREFIX . $name, $seconds, $owner);
    }

    // ── Encoding ────────────────────────────────────────────────────

    protected static function encode(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        self::assertStorable($value);

        return serialize($value);
    }

    protected static function decode(string $raw): mixed
    {
        if (preg_match('/^-?\d{1,19}$/', $raw) === 1) {
            return (int) $raw;
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);

        return $value === false && $raw !== 'b:0;' ? null : $value;
    }

    protected static function assertStorable(mixed $value): void
    {
        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Cache values must be null, scalars or arrays (got ' . get_debug_type($value) . ').');
        }
        if (is_array($value)) {
            array_walk_recursive($value, static function (mixed $v): void {
                if (is_object($v) || is_resource($v)) {
                    throw new InvalidArgumentException('Cache values must be null, scalars or arrays (found ' . get_debug_type($v) . ' inside an array).');
                }
            });
        }
    }

    /** Expiry timestamp for a TTL (0 = never). */
    protected static function expiresAt(int $ttl): int
    {
        return $ttl > 0 ? time() + $ttl : 0;
    }

    protected function key(string $key): string
    {
        return $this->prefix . $key;
    }
}
