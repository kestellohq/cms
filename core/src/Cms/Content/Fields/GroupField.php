<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

/** Nested fields stored as one object. */
final class GroupField extends FieldType
{
    public function name(): string
    {
        return 'group';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return $types->objectSchema($field->getChildren(), false);
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        $errors = [];
        $types->finish($field->getChildren(), (array) $value, '', $errors);

        return array_merge(...array_map(static fn($k, $msgs) => array_map(static fn($m) => "{$k}: {$m}", $msgs), array_keys($errors), $errors)) ?: [];
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        $errors = [];

        return $types->finish($field->getChildren(), (array) $value, '', $errors);
    }

    public function media(mixed $value, Field $field, FieldTypes $types): array
    {
        return array_merge([], ...array_values($types->mediaIn($field->getChildren(), (array) $value)));
    }

    public function relations(mixed $value, Field $field, FieldTypes $types): array
    {
        return array_merge([], ...array_values($types->relationsIn($field->getChildren(), (array) $value)));
    }

    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        return $types->text($field->getChildren(), (array) $value);
    }
}
