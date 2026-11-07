<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Framework\Database\QueryBuilder;

/**
 * A link to terms of one taxonomy, stored in the entry's data (unlike the
 * taxonomy box, which uses entry_term): "featured topic", "related tags".
 * Options: taxonomy (required), multiple, max.
 */
final class TermField extends FieldType
{
    public function name(): string
    {
        return 'term';
    }

    public function component(): string
    {
        return '@core/fields/TermPicker';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        if (!is_string($field->getOption('taxonomy')) || $field->getOption('taxonomy') === '') {
            throw new \InvalidArgumentException("Term field [{$field->name}] needs a taxonomy.");
        }
        $id = Schema::int()->min(1);

        return $field->getOption('multiple', false) ? Schema::list($id)->max((int) $field->getOption('max', 50)) : $id;
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($value) ? $value : [$value])));
        if ($ids === []) {
            return [];
        }
        $found = QueryBuilder::table('terms')->where('taxonomy', (string) $field->getOption('taxonomy'))->whereIn('id', $ids)->pluck('id');

        return count($found) === count($ids) ? [] : ['Pick existing terms.'];
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return is_array($value) ? array_values(array_unique(array_map('intval', $value))) : (int) $value;
    }

    public function index(mixed $value, Field $field): array
    {
        return array_map(static fn($id) => ['number' => (int) $id], is_array($value) ? $value : [$value]);
    }

    public function singleValued(Field $field): bool
    {
        return !$field->getOption('multiple', false);
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        return ['taxonomy' => (string) $field->getOption('taxonomy'), 'multiple' => (bool) $field->getOption('multiple', false)] + $field->getOptions();
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
        $names = array_column(\Kestello\Framework\Database\QueryBuilder::table('terms')->whereIn('id', $ids)->select('id', 'name')->get(), 'name', 'id');

        return implode("\n", array_map(static fn(int $id) => isset($names[$id]) ? ((string) $names[$id] !== '' ? (string) $names[$id] : '') : '#' . $id, $ids));
    }
}
