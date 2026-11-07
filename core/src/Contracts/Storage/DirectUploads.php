<?php

declare(strict_types=1);

namespace Kestello\Contracts\Storage;

/**
 * Optional for disks: browsers upload straight to the storage service, so
 * large files never pass through PHP. The S3 driver implements it.
 *
 * @api
 */
interface DirectUploads
{
    /** Whether this disk is set up for it (S3: "direct_uploads" true, and CORS allowing the site). */
    public function supportsDirectUploads(): bool;

    /**
     * A form the browser POSTs the file to (S3 presigned POST). The policy
     * pins the key, the exact size and the content type.
     *
     * @return array{url: string, fields: array<string, string>}
     * @throws StorageException
     */
    public function presignedPost(string $path, int $seconds, int $size, string $contentType): array;

    /**
     * Up to $length bytes from $offset (an HTTP Range read), e.g. to sniff
     * a directly uploaded file without downloading all of it.
     *
     * @throws FileNotFound
     */
    public function readRange(string $path, int $offset, int $length): string;
}
