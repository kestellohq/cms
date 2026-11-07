<?php

declare(strict_types=1);

namespace Kestello\Contracts\Cache;

use Closure;

/**
 * A lock with an expiry (so a crashed process can't hold it forever) and an
 * owner token (so only the holder releases it).
 *
 * @api
 */
interface Lock
{
    public function acquire(): bool;

    /**
     * Acquire, run $callback and release; returns the callback's result,
     * or false when the lock is held elsewhere. Without a callback, returns
     * whether it was acquired.
     */
    public function get(?Closure $callback = null): mixed;

    /**
     * Wait up to $seconds for the lock, then behave like get().
     *
     * @throws LockTimeout
     */
    public function block(int $seconds, ?Closure $callback = null): mixed;

    /** Release if we still own it. */
    public function release(): bool;

    /** Release regardless of owner (admin tools only). */
    public function forceRelease(): void;

    /** Token identifying the holder; pass it to another process to release there. */
    public function owner(): string;
}
