<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\StorageException;

/** Reads pass through; every write is refused (e.g. a staging site reading production media). */
final class ReadOnlyDisk implements Disk
{
    public function __construct(private readonly Disk $disk) {}

    private static function refuse(): never
    {
        throw new StorageException('This disk is read-only.');
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        self::refuse();
    }
    public function putStream(string $path, mixed $stream, array $options = []): void
    {
        self::refuse();
    }
    public function delete(string $path): bool
    {
        self::refuse();
    }
    public function deleteDirectory(string $path): int
    {
        self::refuse();
    }
    public function copy(string $from, string $to): void
    {
        self::refuse();
    }
    public function move(string $from, string $to): void
    {
        self::refuse();
    }
    public function setVisibility(string $path, string $visibility): void
    {
        self::refuse();
    }
    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string
    {
        self::refuse();
    }

    public function get(string $path): string
    {
        return $this->disk->get($path);
    }
    public function readStream(string $path): mixed
    {
        return $this->disk->readStream($path);
    }
    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }
    public function size(string $path): int
    {
        return $this->disk->size($path);
    }
    public function mimeType(string $path): ?string
    {
        return $this->disk->mimeType($path);
    }
    public function lastModified(string $path): int
    {
        return $this->disk->lastModified($path);
    }
    public function checksum(string $path): string
    {
        return $this->disk->checksum($path);
    }
    public function list(string $prefix = '', bool $recursive = true): iterable
    {
        return $this->disk->list($prefix, $recursive);
    }
    public function visibility(string $path): string
    {
        return $this->disk->visibility($path);
    }
    public function url(string $path): string
    {
        return $this->disk->url($path);
    }
    public function temporaryUrl(string $path, int $seconds, array $options = []): string
    {
        return $this->disk->temporaryUrl($path, $seconds, $options);
    }
}
