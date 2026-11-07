<?php

declare(strict_types=1);

namespace Kestello\Contracts\Storage;

/** @api */
final readonly class FileInfo
{
    public function __construct(
        public string $path,
        public int $size,
        public int $lastModified,
    ) {}
}
