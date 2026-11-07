<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Framework\Database\QueryBuilder;

/** A link to other entries. Options: types (allowed content types), multiple, max. */
final class EntryField extends FieldType
{
    public function name(): string
    {
        return 'entry';
    }

    public function component(): string
    {
        return '@core/fields/EntryPicker';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        $id = Schema::int()->min(1);

        return $field->getOption('multiple', false) ? Schema::list($id)->max((int) $field->getOption('max', 100)) : $id;
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($value) ? $value : [$value])));
        if ($ids === []) {
            return [];
        }
        $q = QueryBuilder::table('entries')->whereIn('id', $ids)->whereNull('deleted_at');
        if (($allowed = (array) $field->getOption('types', [])) !== []) {
            $q->whereIn('type', $allowed);
        }

        return count($q->pluck('id')) === count($ids) ? [] : ['Pick existing entries' . ($allowed !== [] ? ' of type ' . implode(', ', $allowed) : '') . '.'];
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return is_array($value) ? array_values(array_unique(array_map('intval', $value))) : (int) $value;
    }

    public function index(mixed $value, Field $field): array
    {
        return array_map(static fn($id) => ['number' => (int) $id], is_array($value) ? $value : [$value]);
    }

    public function relations(mixed $value, Field $field, FieldTypes $types): array
    {
        return array_values(array_filter(array_map('intval', is_array($value) ? $value : [$value]), static fn(int $id): bool => $id > 0));
    }

    public function singleValued(Field $field): bool
    {
        return !$field->getOption('multiple', false);
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        return ['types' => array_values((array) $field->getOption('types', [])), 'multiple' => (bool) $field->getOption('multiple', false)] + $field->getOptions();
    }

    public function indexKind(Field $field): string
    {
        return 'number';
    }

    /** One line per item: its name, or "#id" for one that no longer exists. */
    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        $ids = array_values(array_filter(array_map('intval', (array) $value)));
        if ($ids === []) {
            return '';
        }
        $names = array_column(\Kestello\Framework\Database\QueryBuilder::table('entries')->whereIn('id', $ids)->select('id', 'title')->get(), 'title', 'id');

        return implode("\n", array_map(static fn(int $id) => isset($names[$id]) ? ((string) $names[$id] !== '' ? (string) $names[$id] : '(untitled)') : '#' . $id, $ids));
    }
}
