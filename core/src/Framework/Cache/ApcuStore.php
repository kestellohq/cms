<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

/**
 * Shared memory of this PHP-FPM pool (one server, fastest). Not shared
 * with the CLI, so jobs and cron see a different cache.
 */
final class ApcuStore extends AbstractStore
{
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = apcu_fetch($this->key($key), $success);

        return $success ? self::decodeRaw($raw) : $default;
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        return apcu_store($this->key($key), self::rawValue($value), $ttl);
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return apcu_add($this->key($key), self::rawValue($value), $ttl);
    }

    public function increment(string $key, int $by = 1): int
    {
        $k = $this->key($key);
        for ($i = 0; $i < 5; $i++) {
            $value = apcu_inc($k, $by, $success);
            if ($success) {
                return (int) $value;
            }
            if (apcu_add($k, $by)) {
                return $by;
            }
        }

        throw new \RuntimeException("Could not increment cache key \"{$key}\".");
    }

    public function forget(string $key): bool
    {
        apcu_delete($this->key($key));

        return true;
    }

    public function forgetIfValue(string $key, mixed $value): bool
    {
        // Not atomic (APCu has no compare-and-delete); the window is tiny.
        $current = apcu_fetch($this->key($key), $success);

        return $success && $current === self::rawValue($value) && apcu_delete($this->key($key));
    }

    public function flush(): bool
    {
        if ($this->prefix === '') {
            return apcu_clear_cache();
        }
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', APC_ITER_KEY);
        foreach ($iterator as $item) {
            apcu_delete($item['key']);
        }

        return true;
    }

    /** Integers stay native (apcu_inc needs them); the rest is encoded. */
    private static function rawValue(mixed $value): int|string
    {
        return is_int($value) ? $value : self::encode($value);
    }

    private static function decodeRaw(mixed $raw): mixed
    {
        return is_int($raw) ? $raw : self::decode((string) $raw);
    }
}
