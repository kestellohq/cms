<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * In-memory state (tests, and boot before the packages table exists).
 */
final class ArrayStateStore implements StateStore
{
    /** @var array<string, PackageState> */
    private array $states = [];

    public function all(PackageType $type): array
    {
        return array_values(array_filter($this->states, static fn(PackageState $s): bool => $s->type === $type));
    }

    public function get(PackageType $type, string $slug): ?PackageState
    {
        return $this->states[$type->value . ':' . $slug] ?? null;
    }

    public function save(PackageState $state): void
    {
        $this->states[$state->type->value . ':' . $state->slug] = $state;
    }

    public function delete(PackageType $type, string $slug): void
    {
        unset($this->states[$type->value . ':' . $slug]);
    }
}
