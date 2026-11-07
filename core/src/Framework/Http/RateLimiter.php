<?php

declare(strict_types=1);

namespace Kestello\Framework\Http;

use Kestello\Contracts\Cache\Store;
use Kestello\Support\Clock;

/**
 * Fixed-window rate limiter on a cache store (atomic add + increment, so
 * concurrent requests and multiple servers count correctly with a shared
 * store).
 *
 *   $result = $limiter->hit('login:ip:203.0.113.9', max: 5, window: 60);
 *   if (!$result->allowed) { ... 429, Retry-After: $result->resetIn ... }
 *
 * Give it a persistent store: the kernel never uses the per-request array
 * store here (limits would silently stop working); it falls back to files.
 */
final class RateLimiter
{
    public function __construct(private readonly Store $store) {}

    public function hit(string $key, int $max, int $window): RateLimitResult
    {
        $window = max(1, $window);
        $now = Clock::timestamp();
        $start = intdiv($now, $window) * $window;
        $resetIn = max(1, $start + $window - $now);
        $bucket = $this->bucket($key, $window, $start);

        $this->store->add($bucket, 0, $resetIn + 1);
        $hits = $this->store->increment($bucket);

        return new RateLimitResult(
            allowed: $hits <= $max,
            limit: $max,
            remaining: max(0, $max - $hits),
            resetIn: $resetIn,
        );
    }

    /** Hits so far in the current window of $window seconds. */
    public function attempts(string $key, int $window = 60): int
    {
        $window = max(1, $window);

        return (int) $this->store->get($this->bucket($key, $window, intdiv(Clock::timestamp(), $window) * $window), 0);
    }

    /** Give back one hit in the current window (never below zero). */
    public function refund(string $key, int $window = 60): void
    {
        $window = max(1, $window);
        $bucket = $this->bucket($key, $window, intdiv(Clock::timestamp(), $window) * $window);
        if ((int) $this->store->get($bucket, 0) > 0) {
            $this->store->decrement($bucket);
        }
    }

    public function clear(string $key, int $window = 60): void
    {
        $window = max(1, $window);
        $this->store->forget($this->bucket($key, $window, intdiv(Clock::timestamp(), $window) * $window));
    }

    private function bucket(string $key, int $window, int $start): string
    {
        return 'rl:' . hash('sha256', $key) . ':' . $window . ':' . $start;
    }
}
