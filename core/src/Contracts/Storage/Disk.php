<?php

declare(strict_types=1);

namespace Kestello\Contracts\Storage;

/**
 * A named place for files ($cms->storage->disk('media')). Paths are
 * relative ("2026/11/photo.jpg"), and every driver refuses "..", absolute
 * paths, backslashes and control characters the same way.
 *
 * Options for put()/putStream(): visibility ('public'|'private'),
 * content_type, cache_control, content_disposition.
 *
 * @api
 */
interface Disk
{
    /** @param array<string, string> $options */
    public function put(string $path, string $contents, array $options = []): void;

    /**
     * @param resource              $stream
     * @param array<string, string> $options
     */
    public function putStream(string $path, mixed $stream, array $options = []): void;

    /** @throws FileNotFound */
    public function get(string $path): string;

    /**
     * @return resource positioned at the start
     * @throws FileNotFound
     */
    public function readStream(string $path): mixed;

    public function exists(string $path): bool;

    /** True when something was deleted. */
    public function delete(string $path): bool;

    /** Delete everything under a directory prefix. Returns how many files. */
    public function deleteDirectory(string $path): int;

    public function copy(string $from, string $to): void;

    public function move(string $from, string $to): void;

    /** @throws FileNotFound */
    public function size(string $path): int;

    public function mimeType(string $path): ?string;

    /** Unix time. @throws FileNotFound */
    public function lastModified(string $path): int;

    /** MD5 hex of the contents (S3: the ETag of single-part uploads). @throws FileNotFound */
    public function checksum(string $path): string;

    /**
     * Files under a prefix, lazily (remote listings are paginated).
     *
     * @return iterable<FileInfo>
     */
    public function list(string $prefix = '', bool $recursive = true): iterable;

    /** 'public' or 'private'. */
    public function visibility(string $path): string;

    public function setVisibility(string $path, string $visibility): void;

    /** Public URL (CDN when configured). @throws StorageException when the disk has no public URL */
    public function url(string $path): string;

    /**
     * A URL that works for $seconds, also for private files.
     *
     * @param array{download_name?: string, content_type?: string} $options
     */
    public function temporaryUrl(string $path, int $seconds, array $options = []): string;

    /**
     * A URL a browser can PUT the file to directly (S3 presigned PUT).
     *
     * @param array{content_type?: string} $constraints
     * @throws StorageException when the driver can't do direct uploads
     */
    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string;
}
