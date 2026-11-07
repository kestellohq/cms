<?php

declare(strict_types=1);

namespace Kestello\Framework\Validation;

/** @api */
final class ValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0][0] ?? 'The given data was invalid.'));
    }
}
