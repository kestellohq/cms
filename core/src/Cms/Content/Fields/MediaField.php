<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Cms\Media\MediaTypes;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Files from the media library. Options: kinds (image, document, audio,
 * video, archive; empty = any), multiple, max. The picker is the
 * replaceable "media.picker" component.
 */
final class MediaField extends FieldType
{
    public function name(): string
    {
        return 'media';
    }

    public function component(): string
    {
        return '@core/fields/MediaPicker';
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
        $q = QueryBuilder::table('media')->whereIn('id', $ids);
        if (($kinds = (array) $field->getOption('kinds', [])) !== []) {
            $q->whereIn('kind', $kinds);
        }

        return count($q->pluck('id')) === count($ids) ? [] : ['Pick files from the media library' . ($kinds !== [] ? ' (' . implode(', ', $kinds) . ')' : '') . '.'];
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

    public function indexKind(Field $field): string
    {
        return 'number';
    }

    public function media(mixed $value, Field $field, FieldTypes $types): array
    {
        return array_values(array_filter(array_map('intval', is_array($value) ? $value : [$value])));
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        $kinds = array_values(array_intersect((array) $field->getOption('kinds', []), MediaTypes::KINDS));

        return ['kinds' => $kinds, 'multiple' => (bool) $field->getOption('multiple', false)] + $field->getOptions();
    }

    /** One line per item: its name, or "#id" for one that no longer exists. */
    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        $ids = array_values(array_filter(array_map('intval', (array) $value)));
        if ($ids === []) {
            return '';
        }
        $names = array_column(\Kestello\Framework\Database\QueryBuilder::table('media')->whereIn('id', $ids)->select('id', 'filename')->get(), 'filename', 'id');

        return implode("\n", array_map(static fn(int $id) => isset($names[$id]) ? ((string) $names[$id] !== '' ? (string) $names[$id] : '') : '#' . $id, $ids));
    }
}
