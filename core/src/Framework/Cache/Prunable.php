<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

/**
 * A store that keeps expired entries until they are removed (files, a
 * database table). Stores that expire entries themselves (Redis, APCu)
 * don't need it.
 */
interface Prunable
{
    /** Delete expired entries. Returns how many. */
    public function prune(): int;
}
