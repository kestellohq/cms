<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * Every module, plugin and theme has booted. Dispatched once per request
 * or command, before the request is handled.
 *
 * @api
 */
final readonly class KernelBooted
{
    /**
     * @param bool $console True for a command, false for a web request.
     * @param bool $recoveryMode True when plugins are skipped (a recovery link, or `--safe`).
     */
    public function __construct(
        public bool $console,
        public bool $recoveryMode,
    ) {}
}
