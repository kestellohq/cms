<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

/**
 * An infrastructure service with swappable drivers (cache, sessions, queue…).
 *
 * @api
 */
final readonly class Subsystem
{
    /**
     * @param string $contract interface every driver instance must implement
     * @param string $default driver used when nothing is configured
     * @param string|null $fallback driver used when the configured one fails to start
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $contract,
        public string $default,
        public ?string $fallback = null,
        public string $description = '',
    ) {}
}
