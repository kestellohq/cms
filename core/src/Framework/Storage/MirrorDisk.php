<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Kestello\Contracts\Storage\Disk;

/**
 * Writes go to two disks, reads come from the first. For moving a site to
 * new storage without downtime, or keeping an offsite copy as files arrive.
 *
 *   'media' => ['driver' => 'mirror', 'disks' => ['media-local', 'media-r2']],
 *
 * A write that fails on the second disk fails the whole write, so the copy
 * never silently falls behind. Deletes are attempted on both.
 */
final class MirrorDisk implements Disk
{
    public function __construct(private readonly Disk $primary, private readonly Disk $secondary) {}

    public function put(string $path, string $contents, array $options = []): void
    {
        $this->primary->put($path, $contents, $options);
        $this->secondary->put($path, $contents, $options);
    }

    public function putStream(string $path, mixed $stream, array $options = []): void
    {
        $this->primary->putStream($path, $stream, $options);
        // the stream is spent: copy what the first disk now holds
        $copy = $this->primary->readStream($path);
        try {
            $this->secondary->putStream($path, $copy, $options);
        } finally {
            is_resource($copy) && fclose($copy);
        }
    }

    public function delete(string $path): bool
    {
        $a = $this->primary->delete($path);
        $b = $this->secondary->delete($path);

        return $a || $b;
    }

    public function deleteDirectory(string $path): int
    {
        $n = $this->primary->deleteDirectory($path);
        $this->secondary->deleteDirectory($path);

        return $n;
    }

    public function copy(string $from, string $to): void
    {
        $this->primary->copy($from, $to);
        $this->secondary->copy($from, $to);
    }

    public function move(string $from, string $to): void
    {
        $this->primary->move($from, $to);
        $this->secondary->move($from, $to);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->primary->setVisibility($path, $visibility);
        $this->secondary->setVisibility($path, $visibility);
    }

    public function get(string $path): string
    {
        return $this->primary->get($path);
    }
    public function readStream(string $path): mixed
    {
        return $this->primary->readStream($path);
    }
    public function exists(string $path): bool
    {
        return $this->primary->exists($path);
    }
    public function size(string $path): int
    {
        return $this->primary->size($path);
    }
    public function mimeType(string $path): ?string
    {
        return $this->primary->mimeType($path);
    }
    public function lastModified(string $path): int
    {
        return $this->primary->lastModified($path);
    }
    public function checksum(string $path): string
    {
        return $this->primary->checksum($path);
    }
    public function list(string $prefix = '', bool $recursive = true): iterable
    {
        return $this->primary->list($prefix, $recursive);
    }
    public function visibility(string $path): string
    {
        return $this->primary->visibility($path);
    }
    public function url(string $path): string
    {
        return $this->primary->url($path);
    }
    public function temporaryUrl(string $path, int $seconds, array $options = []): string
    {
        return $this->primary->temporaryUrl($path, $seconds, $options);
    }

    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string
    {
        throw new \Kestello\Contracts\Storage\StorageException('A mirrored disk takes uploads through the site, so both copies are written.');
    }
}
