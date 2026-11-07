<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Api\Schema;

/**
 * A kind of field: validation (an API Schema), normalization, its value
 * in entry_index and the Vue input that edits it.
 *
 *   final class MapField extends FieldType
 *   {
 *       public function name(): string { return 'acme-maps.point'; }
 *       public function component(): string { return '@acme-maps/fields/Point'; }
 *       public function schema(Field $f, FieldTypes $types): Schema
 *       {
 *           return Schema::object(['lat' => Schema::number()->min(-90)->max(90), 'lng' => Schema::number()->min(-180)->max(180)]);
 *       }
 *   }
 *   $cms->content->fields->register(new MapField());
 *
 * @api
 */
abstract class FieldType
{
    /** Registered name; plugin types start with "<slug>.". */
    abstract public function name(): string;

    /** Schema of a (non-null) value. */
    abstract public function schema(Field $field, FieldTypes $types): Schema;

    /** The admin input component. */
    public function component(): string
    {
        return '@core/fields/' . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $this->name())));
    }

    /**
     * Checks the schema can't express (a referenced entry exists...).
     *
     * @return list<string> error messages
     */
    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        return [];
    }

    /** The value to store, after validation (sanitize HTML, normalize dates...). */
    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return $value;
    }

    /**
     * Values for entry_index when the field is indexed(): a list of
     * ['string' => …] / ['number' => …] / ['date' => 'Y-m-d H:i:s'].
     *
     * @return list<array<string, string|int|float>>
     */
    public function index(mixed $value, Field $field): array
    {
        return [];
    }

    /** Whether one entry has at most one index value (sortable). */
    public function singleValued(Field $field): bool
    {
        return true;
    }

    /** Which entry_index column index() fills: string, number or date (filtering and sorting read it). */
    public function indexKind(Field $field): string
    {
        return 'string';
    }

    /**
     * Media library ids the value refers to, so the library knows which
     * entries use a file (fields holding files, or holding fields that do).
     *
     * @return list<int>
     */
    public function media(mixed $value, Field $field, FieldTypes $types): array
    {
        return [];
    }

    /**
     * Entry ids the value links to, so the linked entries know who links to
     * them (relationship fields, or fields holding fields that are).
     *
     * @return list<int>
     */
    public function relations(mixed $value, Field $field, FieldTypes $types): array
    {
        return [];
    }

    /**
     * The value as plain text, for comparing revisions: one line per item,
     * so a change shows on the line it happened. Override it when the
     * value holds ids or markup that a person would not recognise.
     */
    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        return self::plain($value);
    }

    /** Scalars as text; lists one item per line; objects as "key: value" lines. */
    public static function plain(mixed $value, string $indent = ''): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            return $indent . str_replace("\n", "\n" . $indent, (string) $value);
        }
        $lines = [];
        foreach ((array) $value as $key => $item) {
            $text = is_array($item) ? "\n" . self::plain($item, $indent . '  ') : self::plain($item);
            $lines[] = array_is_list((array) $value) ? $indent . ltrim($text) : $indent . $key . ': ' . ltrim($text);
        }

        return implode("\n", $lines);
    }

    /**
     * Options sent to the admin input (never secrets).
     *
     * @return array<string, mixed>
     */
    public function describe(Field $field, FieldTypes $types): array
    {
        return $field->getOptions();
    }

    /**
     * Help shown under a field of this type that has none of its own, in the
     * current language (a link field: what it takes). Null: none.
     */
    public function defaultHelp(Field $field): ?string
    {
        return null;
    }
}
