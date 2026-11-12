<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

use Closure;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Log\Log;
use Throwable;

/**
 * Wraps the configured session store. If it fails (database table missing,
 * Redis down), the request continues on the fallback store and the failure
 * is logged, so admins can still sign in and fix the settings.
 */
final class FailoverSessionStore implements SessionStore
{
    private bool $failed = false;

    /**
     * @param string|null $breakerFile marker file: while younger than $cooldown
     *                                 seconds, requests go straight to the fallback
     */
    public function __construct(
        private readonly SessionStore $primary,
        private readonly SessionStore $fallback,
        private readonly ?Closure $log = null,
        private readonly ?string $breakerFile = null,
        private readonly int $cooldown = 30,
    ) {
        $this->failed = $breakerFile !== null && is_file($breakerFile) && (time() - (int) @filemtime($breakerFile)) < $cooldown;
    }

    public function active(): SessionStore
    {
        return $this->failed ? $this->fallback : $this->primary;
    }

    public function degraded(): bool
    {
        return $this->failed;
    }

    public function name(): string
    {
        return $this->active()->name();
    }
    public function tracksDevices(): bool
    {
        return $this->active()->tracksDevices();
    }
    public function open(string $path, string $name): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function close(): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function read(string $id): string|false
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function write(string $id, string $data): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function destroy(string $id): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function gc(int $max_lifetime): int|false
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function validateId(string $id): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function forUser(int $userId): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function destroyForUser(int $userId, ?string $exceptHashedId = null): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function destroyHashed(string $hashedId): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @param list<mixed> $args */
    private function call(string $method, array $args): mixed
    {
        if (!$this->failed) {
            try {
                return $this->primary->{$method}(...$args);
            } catch (Throwable $e) {
                $this->failed = true;
                if ($this->breakerFile !== null && (is_dir(dirname($this->breakerFile)) || @mkdir(dirname($this->breakerFile), 0775, true))) {
                    @touch($this->breakerFile);
                }
                ($this->log ?? static fn(string $m) => Log::error($m))(
                    "session store \"{$this->primary->name()}\" failed, using \"{$this->fallback->name()}\": {$e->getMessage()}"
                );
            }
        }

        return $this->fallback->{$method}(...$args);
    }
}
