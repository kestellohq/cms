<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use InvalidArgumentException;

/** A field filter could not be read. errors: field name => messages ("_" for the whole filter). */
final class FieldFilterException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The filter is not valid.');
    }
}
