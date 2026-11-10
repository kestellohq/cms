<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * The site's copy of the plugin and theme registry changed: a refresh brought a new list, and
 * these packages, organisations or advisories are new, different or gone. For a plugin that shows
 * the registry (the Registry reader) and keeps or caches its own copy: update only what changed.
 * Not dispatched when a refresh fails or finds nothing new.
 *
 * @api
 */
final readonly class RegistryRefreshed
{
    /**
     * @param list<string> $packages Slugs of packages added, changed or no longer listed.
     * @param list<string> $organisations Ids of organisations added, changed or no longer listed.
     * @param bool $advisories Whether the advisories changed.
     * @param int $serial The new list's serial.
     */
    public function __construct(
        public array $packages,
        public array $organisations,
        public bool $advisories,
        public int $serial,
    ) {}
}
