<?php

declare(strict_types=1);

namespace Kestello\Contracts\Cache;

use Closure;

/**
 * A key-value cache. Values are null, scalars and arrays of those: objects
 * are rejected, so nothing read back from a cache can instantiate classes.
 *
 * TTLs are in seconds; 0 means "no expiry". Keys are prefixed per site by
 * the store, so several sites can share one Redis or database.
 *
 *   $cache = $cms->container->make(Store::class);
 *   $menu = $cache->remember('menu:main', 3600, fn() => buildMenu());
 *   $cache->lock('import', 120)->get(fn() => runImport());
 *
 * @api
 */
interface Store
{
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param list<string> $keys
     * @return array<string, mixed> missing keys map to null
     */
    public function many(array $keys): array;

    public function put(string $key, mixed $value, int $ttl = 0): bool;

    /** @param array<string, mixed> $values */
    public function putMany(array $values, int $ttl = 0): bool;

    /** Store only if the key doesn't exist (atomic). */
    public function add(string $key, mixed $value, int $ttl = 0): bool;

    /** Atomic increment. A missing key starts at 0 (no expiry); an existing key keeps its TTL. */
    public function increment(string $key, int $by = 1): int;

    public function decrement(string $key, int $by = 1): int;

    public function has(string $key): bool;

    public function forget(string $key): bool;

    /** Remove every key of this store (this site's prefix only). */
    public function flush(): bool;

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed;

    public function pull(string $key, mixed $default = null): mixed;

    /** A mutex shared by every process using this store. */
    public function lock(string $name, int $seconds = 10, ?string $owner = null): Lock;
}
