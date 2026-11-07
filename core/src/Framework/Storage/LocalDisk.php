<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Closure;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileInfo;
use Kestello\Contracts\Storage\FileNotFound;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Support\Mime;

/**
 * Files under a root directory. Writes are atomic (temp file + rename).
 * Symlinks inside the root are never followed out of it. Visibility is the
 * file mode (0644 public / 0600 private).
 */
final class LocalDisk implements Disk
{
    private string $root;

    /**
     * @param string|null  $url    public base URL ("/files", "https://cdn.example.com"); null = not public
     * @param (Closure(string $path, int $seconds, array<string, string> $options): string)|null $signer temporary URLs
     */
    public function __construct(
        string $root,
        private readonly ?string $url = null,
        private readonly string $defaultVisibility = 'private',
        private readonly ?Closure $signer = null,
    ) {
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new StorageException("Cannot create the storage directory {$root}.");
        }
        $this->root = rtrim((string) realpath($root), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Absolute path of an existing or new file, confined to the root. */
    public function path(string $path): string
    {
        $full = $this->root . '/' . StoragePath::file($path);
        // An existing parent that resolves outside the root (symlink) is refused.
        $dir = dirname($full);
        while (!is_dir($dir) && $dir !== $this->root && strlen($dir) > strlen($this->root)) {
            $dir = dirname($dir);
        }
        $real = realpath($dir);
        if ($real === false || ($real !== $this->root && !str_starts_with($real . '/', $this->root . '/'))) {
            throw new StorageException("Path \"{$path}\" leaves the disk.");
        }
        if (is_link($full)) {
            $target = realpath($full);
            if ($target === false || !str_starts_with($target, $this->root . '/')) {
                throw new StorageException("Path \"{$path}\" leaves the disk.");
            }
        }

        return $full;
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);
        $this->putStream($path, $stream, $options);
        fclose($stream);
    }

    public function putStream(string $path, mixed $stream, array $options = []): void
    {
        $full = $this->path($path);
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageException("Cannot create directory for \"{$path}\".");
        }
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        $out = @fopen($tmp, 'x');
        if ($out === false) {
            throw new StorageException("Cannot write \"{$path}\".");
        }
        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new StorageException("Cannot write \"{$path}\".");
            }
        } finally {
            fclose($out);
        }
        chmod($tmp, ($options['visibility'] ?? $this->defaultVisibility) === 'public' ? 0644 : 0600);
        if (!@rename($tmp, $full)) {
            @unlink($tmp);
            throw new StorageException("Cannot write \"{$path}\".");
        }
    }

    public function get(string $path): string
    {
        $full = $this->existing($path);
        $data = @file_get_contents($full);

        return $data === false ? throw new StorageException("Cannot read \"{$path}\".") : $data;
    }

    public function readStream(string $path): mixed
    {
        $handle = @fopen($this->existing($path), 'rb');

        return $handle === false ? throw new StorageException("Cannot read \"{$path}\".") : $handle;
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function delete(string $path): bool
    {
        $full = $this->path($path);

        return is_file($full) && @unlink($full);
    }

    public function deleteDirectory(string $path): int
    {
        $prefix = StoragePath::directory($path);
        if ($prefix === '') {
            throw new StorageException('Refusing to delete the whole disk.');
        }
        $count = 0;
        foreach ($this->list($prefix) as $file) {
            $count += (int) $this->delete($file->path);
        }
        $this->pruneEmptyDirs($this->root . '/' . rtrim($prefix, '/'));

        return $count;
    }

    public function copy(string $from, string $to): void
    {
        $in = $this->readStream($from);
        try {
            $this->putStream($to, $in, ['visibility' => $this->visibility($from)]);
        } finally {
            fclose($in);
        }
    }

    public function move(string $from, string $to): void
    {
        $source = $this->existing($from);
        $target = $this->path($to);
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        if (!@rename($source, $target)) {
            throw new StorageException("Cannot move \"{$from}\" to \"{$to}\".");
        }
    }

    public function size(string $path): int
    {
        clearstatcache();

        return (int) filesize($this->existing($path));
    }

    public function mimeType(string $path): ?string
    {
        if (!$this->exists($path)) {
            return null;
        }
        $byName = Mime::fromExtension($path);
        if ($byName !== 'application/octet-stream') {
            return $byName;
        }
        $sniffed = function_exists('mime_content_type') ? @mime_content_type($this->path($path)) : false;

        return is_string($sniffed) ? $sniffed : $byName;
    }

    public function lastModified(string $path): int
    {
        clearstatcache();

        return (int) filemtime($this->existing($path));
    }

    public function checksum(string $path): string
    {
        return (string) md5_file($this->existing($path));
    }

    public function list(string $prefix = '', bool $recursive = true): iterable
    {
        $prefix = StoragePath::directory($prefix);
        $dir = $this->root . ($prefix === '' ? '' : '/' . rtrim($prefix, '/'));
        if (!is_dir($dir)) {
            return;
        }
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS))
            : new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $files = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && !$file->isLink() && !str_starts_with($file->getFilename(), '.tmp-')) {
                $files[] = new FileInfo(substr($file->getPathname(), strlen($this->root) + 1), $file->getSize(), $file->getMTime());
            }
        }
        usort($files, static fn(FileInfo $a, FileInfo $b) => strcmp($a->path, $b->path));
        yield from $files;
    }

    public function visibility(string $path): string
    {
        return (fileperms($this->existing($path)) & 0004) !== 0 ? 'public' : 'private';
    }

    public function setVisibility(string $path, string $visibility): void
    {
        chmod($this->existing($path), $visibility === 'public' ? 0644 : 0600);
    }

    public function url(string $path): string
    {
        if ($this->url === null) {
            throw new StorageException('This disk has no public URL; use temporaryUrl().');
        }

        return rtrim($this->url, '/') . '/' . StoragePath::encode(StoragePath::file($path));
    }

    public function temporaryUrl(string $path, int $seconds, array $options = []): string
    {
        if ($this->signer === null) {
            throw new StorageException('Temporary URLs are not configured for this disk.');
        }

        return ($this->signer)(StoragePath::file($path), max(1, $seconds), $options);
    }

    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string
    {
        throw new StorageException('Local disks take uploads through the API, not direct URLs.');
    }

    private function existing(string $path): string
    {
        $full = $this->path($path);

        return is_file($full) ? $full : throw FileNotFound::at($path);
    }

    private function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir) || $dir === $this->root) {
            return;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $child) {
            if ($child->isDir()) {
                $this->pruneEmptyDirs($child->getPathname());
            }
        }
        @rmdir($dir); // only succeeds when empty
    }
}
