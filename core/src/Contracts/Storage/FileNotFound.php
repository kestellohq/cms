<?php

declare(strict_types=1);

namespace Kestello\Contracts\Storage;

/** @api */
final class FileNotFound extends StorageException
{
    public static function at(string $path): self
    {
        return new self("File not found: {$path}");
    }
}
