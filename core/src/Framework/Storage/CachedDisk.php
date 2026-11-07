<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Kestello\Contracts\Cache\Store;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileNotFound;

/**
 * Remembers file metadata (exists, size, type, modified, checksum,
 * visibility) in the cache store, so slow remote disks aren't asked the
 * same question on every request. Writes through this disk forget what
 * they change; changes made behind its back show after the TTL.
 *
 *   'media' => ['driver' => 'cached', 'disk' => 'media-s3', 'ttl' => 3600],
 *
 * Contents and listings are never cached.
 */
final class CachedDisk implements Disk
{
    private const array FIELDS = ['exists', 'size', 'mime', 'modified', 'checksum', 'visibility'];

    public function __construct(
        private readonly Disk $disk,
        private readonly Store $cache,
        private readonly string $name,
        private readonly int $ttl = 3600,
    ) {}

    private function key(string $path, string $field): string
    {
        return 'disk-meta:' . $this->name . ':' . $field . ':' . sha1(StoragePath::file($path));
    }

    /** @template T @param \Closure(): T $read @return T */
    private function remember(string $path, string $field, \Closure $read): mixed
    {
        $key = $this->key($path, $field);
        $hit = $this->cache->get($key);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            if ($hit['v'] === false && $field !== 'exists') {
                throw FileNotFound::at($path);        // remembered as missing
            }

            return $hit['v'];
        }
        try {
            $value = $read();
        } catch (FileNotFound $e) {
            $this->cache->put($key, ['v' => false], min($this->ttl, 60));
            throw $e;
        }
        $this->cache->put($key, ['v' => $value], $this->ttl);

        return $value;
    }

    private function forget(string $path): void
    {
        foreach (self::FIELDS as $f) {
            $this->cache->forget($this->key($path, $f));
        }
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $this->disk->put($path, $contents, $options);
        $this->forget($path);
    }

    public function putStream(string $path, mixed $stream, array $options = []): void
    {
        $this->disk->putStream($path, $stream, $options);
        $this->forget($path);
    }

    public function delete(string $path): bool
    {
        $this->forget($path);

        return $this->disk->delete($path);
    }

    public function deleteDirectory(string $path): int
    {
        // can't name every key under the directory: forget what we list first
        foreach ($this->disk->list($path) as $file) {
            $this->forget($file->path);
        }

        return $this->disk->deleteDirectory($path);
    }

    public function copy(string $from, string $to): void
    {
        $this->disk->copy($from, $to);
        $this->forget($to);
    }

    public function move(string $from, string $to): void
    {
        $this->disk->move($from, $to);
        $this->forget($from);
        $this->forget($to);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->disk->setVisibility($path, $visibility);
        $this->cache->forget($this->key($path, 'visibility'));
    }

    public function exists(string $path): bool
    {
        $key = $this->key($path, 'exists');
        $hit = $this->cache->get($key);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return (bool) $hit['v'];
        }
        $v = $this->disk->exists($path);
        $this->cache->put($key, ['v' => $v], $v ? $this->ttl : min($this->ttl, 60));

        return $v;
    }

    public function size(string $path): int
    {
        return $this->remember($path, 'size', fn() => $this->disk->size($path));
    }
    public function mimeType(string $path): ?string
    {
        return $this->remember($path, 'mime', fn() => $this->disk->mimeType($path));
    }
    public function lastModified(string $path): int
    {
        return $this->remember($path, 'modified', fn() => $this->disk->lastModified($path));
    }
    public function checksum(string $path): string
    {
        return $this->remember($path, 'checksum', fn() => $this->disk->checksum($path));
    }
    public function visibility(string $path): string
    {
        return $this->remember($path, 'visibility', fn() => $this->disk->visibility($path));
    }

    public function get(string $path): string
    {
        return $this->disk->get($path);
    }
    public function readStream(string $path): mixed
    {
        return $this->disk->readStream($path);
    }
    public function list(string $prefix = '', bool $recursive = true): iterable
    {
        return $this->disk->list($prefix, $recursive);
    }
    public function url(string $path): string
    {
        return $this->disk->url($path);
    }
    public function temporaryUrl(string $path, int $seconds, array $options = []): string
    {
        return $this->disk->temporaryUrl($path, $seconds, $options);
    }
    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string
    {
        return $this->disk->temporaryUploadUrl($path, $seconds, $constraints);
    }
}
