<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

use Closure;
use Kestello\Contracts\Cache\Lock;
use Kestello\Contracts\Cache\Store;
use Throwable;

/**
 * Wraps the configured store. When it throws (Redis down, table missing),
 * the call is retried on the fallback, the failure is logged, and a circuit
 * breaker skips the primary for $cooldown seconds instead of timing out on
 * every call. The breaker is a marker file, so it holds across requests.
 */
final class FailoverStore implements Store
{
    private ?Store $primary = null;
    private bool $open = false;

    /** @param Closure(): Store $primary built lazily */
    public function __construct(
        private readonly Closure $resolvePrimary,
        private readonly Store $fallback,
        private readonly string $breakerFile,
        private readonly int $cooldown = 30,
        private readonly ?Closure $log = null,
    ) {
        $this->open = is_file($breakerFile) && (time() - (int) @filemtime($breakerFile)) < $cooldown;
    }

    /** Whether calls currently go to the fallback. */
    public function degraded(): bool
    {
        return $this->open;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function many(array $keys): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function putMany(array $values, int $ttl = 0): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function increment(string $key, int $by = 1): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function decrement(string $key, int $by = 1): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function has(string $key): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function forget(string $key): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function flush(): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function lock(string $name, int $seconds = 10, ?string $owner = null): Lock
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        // Not delegated as a whole: the callback must run once even if the store fails mid-way.
        $missing = new \stdClass();
        $value = $this->get($key, $missing);
        if ($value !== $missing) {
            return $value;
        }
        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /** @param list<mixed> $args */
    private function call(string $method, array $args): mixed
    {
        if (!$this->open) {
            try {
                $this->primary ??= ($this->resolvePrimary)();

                return $this->primary->{$method}(...$args);
            } catch (\InvalidArgumentException $e) {
                throw $e; // caller error (e.g. an object value), not an outage
            } catch (Throwable $e) {
                $this->trip($e);
            }
        }

        return $this->fallback->{$method}(...$args);
    }

    private function trip(Throwable $e): void
    {
        $this->open = true;
        $dir = dirname($this->breakerFile);
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            @touch($this->breakerFile);
        }
        ($this->log ?? static fn(string $m) => error_log($m))(
            "[kestello] cache store failed, using fallback for {$this->cooldown}s: {$e->getMessage()}"
        );
    }
}
