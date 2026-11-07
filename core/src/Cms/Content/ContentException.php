<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use RuntimeException;

/** A content write that was refused: invalid input, not allowed, or a stale version. */
final class ContentException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    private function __construct(string $message, public readonly string $kind, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors): self
    {
        return new self('The entry is not valid.', 'invalid', $errors);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'forbidden');
    }

    public static function notFound(string $message): self
    {
        return new self($message, 'not_found');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'conflict');
    }
}
