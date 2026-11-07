<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

/** In-memory layer 3 (tests, and sites without a database yet). */
final class ArrayPlatformStore implements PlatformStore
{
    /** @param array<string, array{driver: string, options: array<string, mixed>}> $values */
    public function __construct(private array $values = []) {}

    public function load(): array
    {
        return $this->values;
    }

    public function save(string $subsystem, ?array $value): void
    {
        if ($value === null) {
            unset($this->values[$subsystem]);
        } else {
            $this->values[$subsystem] = $value;
        }
    }
}
