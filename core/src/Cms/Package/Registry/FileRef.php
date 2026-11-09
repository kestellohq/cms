<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

/**
 * Another registry file as the signed index names it: where it is, its SHA-256 and its size.
 * The index's signature covers these, so a file that matches them is as trusted as the index.
 *
 * @internal
 */
final readonly class FileRef
{
    private function __construct(
        public string $path,
        public string $sha256,
        public int $size,
    ) {}

    /** The reference, or null when it isn't one or names a file over $maxBytes. */
    public static function parse(mixed $v, int $maxBytes): ?self
    {
        if (!is_array($v)) {
            return null;
        }
        $path = Fields::path($v['path'] ?? null);
        $sha = Fields::sha256($v['sha256'] ?? null);
        $size = $v['size'] ?? null;

        return $path !== null && $sha !== null && is_int($size) && $size > 0 && $size <= $maxBytes ? new self($path, $sha, $size) : null;
    }

    /**
     * $bytes, when they are exactly the file the index names.
     *
     * @throws RegistryException
     */
    public function check(string $bytes): string
    {
        if (strlen($bytes) !== $this->size || !hash_equals($this->sha256, hash('sha256', $bytes))) {
            throw new RegistryException("The registry file {$this->path} doesn't match the index. A mirror or cache may hold a damaged copy.");
        }

        return $bytes;
    }
}
