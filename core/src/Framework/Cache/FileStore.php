<?php

declare(strict_types=1);

namespace Kestello\Framework\Cache;

use RuntimeException;

/**
 * One file per key under a dedicated directory. Every access takes an flock
 * on the key's file, so add() and increment() are atomic across processes
 * on one server (not across servers on NFS).
 *
 * File format: 10-digit expiry timestamp (0 = never), then the encoded value.
 */
final class FileStore extends AbstractStore implements Prunable
{
    public function __construct(private readonly string $directory, string $prefix = '')
    {
        parent::__construct($prefix);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return $default;
        }

        $raw = $this->withFile($path, LOCK_SH, static fn($h) => stream_get_contents($h), create: false);
        $item = is_string($raw) ? self::parse($raw) : null;

        if ($item === null) {
            if (is_string($raw) && $raw !== '') {
                @unlink($path); // expired
            }
            return $default;
        }

        return self::decode($item[0]);
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $encoded = self::encode($value);

        return $this->withFile($this->path($key), LOCK_EX, static fn($h) => self::write($h, $encoded, self::expiresAt($ttl)));
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $encoded = self::encode($value);

        return $this->withFile($this->path($key), LOCK_EX, static function ($h) use ($encoded, $ttl): bool {
            if (self::parse((string) stream_get_contents($h)) !== null) {
                return false;
            }

            return self::write($h, $encoded, self::expiresAt($ttl));
        });
    }

    public function increment(string $key, int $by = 1): int
    {
        return $this->withFile($this->path($key), LOCK_EX, static function ($h) use ($by): int {
            $item = self::parse((string) stream_get_contents($h));
            $value = ($item === null ? 0 : (int) self::decode($item[0])) + $by;
            self::write($h, (string) $value, $item[1] ?? 0);

            return $value;
        });
    }

    public function forget(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || @unlink($path);
    }

    public function forgetIfValue(string $key, mixed $value): bool
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return false;
        }
        $expected = self::encode($value);

        $matched = $this->withFile($path, LOCK_EX, static function ($h) use ($expected): bool {
            $item = self::parse((string) stream_get_contents($h));
            if ($item === null || $item[0] !== $expected) {
                return false;
            }
            ftruncate($h, 0); // emptied = missing, even if unlink below races

            return true;
        }, create: false);

        if ($matched) {
            @unlink($path);
        }

        return (bool) $matched;
    }

    public function flush(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $ok = true;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->getFilename() === '.gitkeep') {
                continue;
            }
            $ok = ($item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname())) && $ok;
        }

        return $ok;
    }

    /** Delete expired files (run from the scheduler). Returns the number removed. */
    public function prune(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.cache')) {
                continue;
            }
            $head = (string) @file_get_contents($file->getPathname(), false, null, 0, 10);
            $expires = (int) $head;
            if ($head === '' || ($expires !== 0 && $expires <= time())) {
                $removed += (int) @unlink($file->getPathname());
            }
        }

        return $removed;
    }

    /** @return array{0: string, 1: int}|null [encoded, expires] when present and live */
    private static function parse(string $raw): ?array
    {
        if (strlen($raw) < 10) {
            return null;
        }
        $expires = (int) substr($raw, 0, 10);
        if ($expires !== 0 && $expires <= time()) {
            return null;
        }

        return [substr($raw, 10), $expires];
    }

    /** @param resource $h */
    private static function write($h, string $encoded, int $expires): bool
    {
        ftruncate($h, 0);
        rewind($h);
        $ok = fwrite($h, sprintf('%010d', $expires) . $encoded) !== false;
        fflush($h);

        return $ok;
    }

    /**
     * @template T
     * @param \Closure(resource): T $callback
     * @return T|false
     */
    private function withFile(string $path, int $lock, \Closure $callback, bool $create = true): mixed
    {
        if ($create) {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cache directory is not writable: {$dir}");
            }
        }

        $handle = @fopen($path, $create ? 'c+' : 'r+');
        if ($handle === false) {
            if ($create) {
                throw new RuntimeException("Cache file is not writable: {$path}");
            }
            return false;
        }

        try {
            flock($handle, $lock);

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $this->key($key));

        return $this->directory . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2) . '.cache';
    }
}
