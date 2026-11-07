<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

/** @internal reads a key file written by package:keygen */
final class SignKey
{
    public static function read(string $file): string
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || !is_string($data['secret_key'] ?? null)) {
            throw new \RuntimeException("{$file} is not a key file from package:keygen.");
        }

        return $data['secret_key'];
    }
}
