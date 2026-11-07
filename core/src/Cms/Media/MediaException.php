<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use RuntimeException;

/** A media operation that was refused: invalid file or input, not allowed, or in use. */
final class MediaException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    private function __construct(string $message, public readonly string $kind, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors, string $message = 'The file was refused.'): self
    {
        return new self($message, 'invalid', $errors);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'forbidden');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'conflict');
    }
}
