<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * Persisted state of an installed package.
 */
final readonly class PackageState
{
    public function __construct(
        public PackageType $type,
        public string $slug,
        public bool $active,
        public string $version,
    ) {}

    public function with(?bool $active = null, ?string $version = null): self
    {
        return new self($this->type, $this->slug, $active ?? $this->active, $version ?? $this->version);
    }
}
