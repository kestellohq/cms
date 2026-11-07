<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use RuntimeException;

/** A platform configuration was rejected. $errors holds field errors when validation failed. */
final class PlatformException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, public readonly array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
