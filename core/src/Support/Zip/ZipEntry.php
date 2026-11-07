<?php

declare(strict_types=1);

namespace Kestello\Support\Zip;

/** @api */
final readonly class ZipEntry
{
    /** @internal */
    public function __construct(
        public string $name,
        public int $size,
        public int $compressedSize,
        public int $method,
        public int $crc,
        public int $offset,
        public bool $isDirectory,
        public bool $isSymlink,
        public bool $encrypted,
        public int $modified,
        /** Unix permission bits recorded in the archive (0 when none). */
        public int $mode = 0,
    ) {}
}
