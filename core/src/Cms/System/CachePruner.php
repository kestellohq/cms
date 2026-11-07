<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Kestello\Framework\Cache\Prunable;

/**
 * Removes expired cache entries from the stores that keep them. Rate
 * limits alone leave one entry per visitor per window; without this the
 * cache directory or table grows until the disk is full.
 */
final class CachePruner
{
    /**
     * @param iterable<object> $stores stores that aren't Prunable are skipped
     * @return int entries removed
     */
    public static function prune(iterable $stores): int
    {
        $removed = 0;
        foreach ($stores as $store) {
            if ($store instanceof Prunable) {
                $removed += $store->prune();
            }
        }

        return $removed;
    }
}
