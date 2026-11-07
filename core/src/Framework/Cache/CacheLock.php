<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

use Closure;
use Kestello\Contracts\Cache\Lock;
use Kestello\Contracts\Cache\LockTimeout;

/** A lock stored as a cache key holding the owner token. */
final class CacheLock implements Lock
{
    private readonly string $owner;

    public function __construct(
        private readonly AbstractStore $store,
        private readonly string $key,
        private readonly int $seconds,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? bin2hex(random_bytes(16));
    }

    public function acquire(): bool
    {
        return $this->store->add($this->key, $this->owner, max(1, $this->seconds));
    }

    public function get(?Closure $callback = null): mixed
    {
        if (!$this->acquire()) {
            return false;
        }
        if ($callback === null) {
            return true;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function block(int $seconds, ?Closure $callback = null): mixed
    {
        $deadline = microtime(true) + $seconds;

        while (!$this->acquire()) {
            if (microtime(true) >= $deadline) {
                throw new LockTimeout("Could not acquire lock \"{$this->key}\" within {$seconds} s.");
            }
            usleep(100_000 + random_int(0, 50_000));
        }

        if ($callback === null) {
            return true;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function release(): bool
    {
        return $this->store->forgetIfValue($this->key, $this->owner);
    }

    public function forceRelease(): void
    {
        $this->store->forget($this->key);
    }

    public function owner(): string
    {
        return $this->owner;
    }
}
