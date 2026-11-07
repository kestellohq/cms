<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

/** Per-request memory. Tests, and the safe fallback when a real store fails. */
final class ArrayStore extends AbstractStore
{
    /** @var array<string, array{0: string, 1: int}> key => [encoded, expires] */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->live($key);

        return $item === null ? $default : self::decode($item[0]);
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->items[$this->key($key)] = [self::encode($value), self::expiresAt($ttl)];

        return true;
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->live($key) === null && $this->put($key, $value, $ttl);
    }

    public function increment(string $key, int $by = 1): int
    {
        $item = $this->live($key);
        $value = ($item === null ? 0 : (int) self::decode($item[0])) + $by;
        $this->items[$this->key($key)] = [(string) $value, $item[1] ?? 0];

        return $value;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$this->key($key)]);

        return true;
    }

    public function forgetIfValue(string $key, mixed $value): bool
    {
        $item = $this->live($key);
        if ($item === null || $item[0] !== self::encode($value)) {
            return false;
        }

        return $this->forget($key);
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    /** @return array{0: string, 1: int}|null */
    private function live(string $key): ?array
    {
        $k = $this->key($key);
        $item = $this->items[$k] ?? null;
        if ($item !== null && $item[1] !== 0 && $item[1] <= time()) {
            unset($this->items[$k]);
            return null;
        }

        return $item;
    }
}
