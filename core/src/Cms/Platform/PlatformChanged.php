<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

/**
 * The settings of a platform service (cache, sessions, queue, search) were
 * changed or reset on the Infrastructure screen, or with `platform:set`
 * or `platform:reset`.
 *
 * @api
 */
final readonly class PlatformChanged
{
    /**
     * @param string $subsystem `cache`, `session`, `queue`, `search`, or a service a plugin adds.
     * @param string $previousDriver The driver before the change.
     * @param string $driver The driver now.
     * @param list<string> $changed The names of the options whose value changed. Secrets are named, never included.
     * @param ?int $userId Who changed it.
     * @param bool $reset True when the screen's settings were removed, back to the configuration file and environment.
     */
    public function __construct(
        public string $subsystem,
        public string $previousDriver,
        public string $driver,
        public array $changed,
        public ?int $userId,
        public bool $reset = false,
    ) {}
}
