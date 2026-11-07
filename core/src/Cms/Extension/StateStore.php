<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * Where package activation state is persisted.
 */
interface StateStore
{
    /**
     * @return list<PackageState>
     */
    public function all(PackageType $type): array;

    public function get(PackageType $type, string $slug): ?PackageState;

    public function save(PackageState $state): void;

    public function delete(PackageType $type, string $slug): void;
}
