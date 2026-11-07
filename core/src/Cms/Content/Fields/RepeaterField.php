<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

/** A list of rows, each with the same nested fields. Options: min, max (rows; default 100). */
final class RepeaterField extends FieldType
{
    public function name(): string
    {
        return 'repeater';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        $s = Schema::list($types->objectSchema($field->getChildren(), false))->max((int) $field->getOption('max', 100));

        return ($min = $field->getOption('min')) !== null ? $s->min((int) $min) : $s;
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        $out = [];
        foreach ((array) $value as $i => $row) {
            $errors = [];
            $types->finish($field->getChildren(), (array) $row, '', $errors);
            foreach ($errors as $key => $messages) {
                foreach ($messages as $m) {
                    $out[] = 'Row ' . ((int) $i + 1) . ", {$key}: {$m}";
                }
            }
        }

        return $out;
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        $rows = [];
        foreach ((array) $value as $row) {
            $errors = [];
            $rows[] = $types->finish($field->getChildren(), (array) $row, '', $errors);
        }

        return $rows;
    }

    public function media(mixed $value, Field $field, FieldTypes $types): array
    {
        $ids = [];
        foreach ((array) $value as $row) {
            $ids = array_merge($ids, ...array_values($types->mediaIn($field->getChildren(), (array) $row)));
        }

        return $ids;
    }

    public function relations(mixed $value, Field $field, FieldTypes $types): array
    {
        $ids = [];
        foreach ((array) $value as $row) {
            $ids = array_merge($ids, ...array_values($types->relationsIn($field->getChildren(), (array) $row)));
        }

        return $ids;
    }

    /** Each row as "1. Label: value" lines. */
    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        $out = [];
        foreach (array_values((array) $value) as $i => $row) {
            $text = $types->text($field->getChildren(), (array) $row);
            $out[] = ($i + 1) . '. ' . str_replace("\n", "\n   ", $text);
        }

        return implode("\n", $out);
    }
}
