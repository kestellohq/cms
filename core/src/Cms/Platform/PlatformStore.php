<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

/**
 * Where admin-edited platform settings (layer 3) are persisted. Secrets
 * arrive here already sealed.
 */
interface PlatformStore
{
    /** @return array<string, array{driver: string, options: array<string, mixed>}> */
    public function load(): array;

    /** @param array{driver: string, options: array<string, mixed>}|null $value null removes it */
    public function save(string $subsystem, ?array $value): void;
}
