<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

final class BooleanField extends FieldType
{
    public function name(): string
    {
        return 'boolean';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return Schema::bool();
    }

    public function index(mixed $value, Field $field): array
    {
        return [['number' => $value ? 1 : 0]];
    }

    public function indexKind(Field $field): string
    {
        return 'number';
    }
}
