<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use RuntimeException;

/**
 * A plugin.json / theme.json is missing or invalid.
 */
final class ManifestException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public static function invalid(string $file, array $errors): self
    {
        return new self(sprintf("Invalid %s:\n  - %s", $file, implode("\n  - ", $errors)));
    }
}
