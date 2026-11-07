<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileInfo;

/** Another disk under a fixed prefix ("sites/42/"). */
final class ScopedDisk implements Disk, \Kestello\Contracts\Storage\DirectUploads
{
    private string $prefix;

    public function __construct(private readonly Disk $disk, string $prefix)
    {
        $this->prefix = StoragePath::directory($prefix);
    }

    private function p(string $path): string
    {
        return $this->prefix . StoragePath::file($path);
    }
    private function d(string $path): string
    {
        return $this->prefix . StoragePath::directory($path);
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $this->disk->put($this->p($path), $contents, $options);
    }
    public function putStream(string $path, mixed $stream, array $options = []): void
    {
        $this->disk->putStream($this->p($path), $stream, $options);
    }
    public function get(string $path): string
    {
        return $this->disk->get($this->p($path));
    }
    public function readStream(string $path): mixed
    {
        return $this->disk->readStream($this->p($path));
    }
    public function exists(string $path): bool
    {
        return $this->disk->exists($this->p($path));
    }
    public function delete(string $path): bool
    {
        return $this->disk->delete($this->p($path));
    }
    public function deleteDirectory(string $path): int
    {
        return $this->disk->deleteDirectory($this->d($path));
    }
    public function copy(string $from, string $to): void
    {
        $this->disk->copy($this->p($from), $this->p($to));
    }
    public function move(string $from, string $to): void
    {
        $this->disk->move($this->p($from), $this->p($to));
    }
    public function size(string $path): int
    {
        return $this->disk->size($this->p($path));
    }
    public function mimeType(string $path): ?string
    {
        return $this->disk->mimeType($this->p($path));
    }
    public function lastModified(string $path): int
    {
        return $this->disk->lastModified($this->p($path));
    }
    public function checksum(string $path): string
    {
        return $this->disk->checksum($this->p($path));
    }
    public function visibility(string $path): string
    {
        return $this->disk->visibility($this->p($path));
    }
    public function setVisibility(string $path, string $visibility): void
    {
        $this->disk->setVisibility($this->p($path), $visibility);
    }
    public function url(string $path): string
    {
        return $this->disk->url($this->p($path));
    }
    public function temporaryUrl(string $path, int $seconds, array $options = []): string
    {
        return $this->disk->temporaryUrl($this->p($path), $seconds, $options);
    }
    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string
    {
        return $this->disk->temporaryUploadUrl($this->p($path), $seconds, $constraints);
    }

    public function supportsDirectUploads(): bool
    {
        return $this->disk instanceof \Kestello\Contracts\Storage\DirectUploads && $this->disk->supportsDirectUploads();
    }

    public function presignedPost(string $path, int $seconds, int $size, string $contentType): array
    {
        $this->supportsDirectUploads() || throw new \Kestello\Contracts\Storage\StorageException('This disk does not take direct uploads.');

        return $this->disk->presignedPost($this->p($path), $seconds, $size, $contentType);
    }

    public function readRange(string $path, int $offset, int $length): string
    {
        if ($this->disk instanceof \Kestello\Contracts\Storage\DirectUploads) {
            return $this->disk->readRange($this->p($path), $offset, $length);
        }
        $stream = $this->disk->readStream($this->p($path));
        fseek($stream, $offset);

        return (string) fread($stream, max(1, $length));
    }

    public function list(string $prefix = '', bool $recursive = true): iterable
    {
        foreach ($this->disk->list($this->d($prefix), $recursive) as $file) {
            yield new FileInfo(substr($file->path, strlen($this->prefix)), $file->size, $file->lastModified);
        }
    }
}
