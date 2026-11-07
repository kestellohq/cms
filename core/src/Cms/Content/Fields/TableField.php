<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

/**
 * A table of plain-text cells: {"head": true, "rows": [["Plan", "Price"], ["Basic", "€9"]]}.
 * With head, the first row is the column headings. Every row has the same
 * number of cells. Options: columns (most columns, default 12), max (most rows, default 200).
 */
final class TableField extends FieldType
{
    public const int CELL_MAX = 1000;

    public function name(): string
    {
        return 'table';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        $cells = Schema::list(Schema::string()->max(self::CELL_MAX))->min(1)->max((int) $field->getOption('columns', 12));

        return Schema::object([
            'head' => Schema::bool()->default(false),
            'rows' => Schema::list($cells)->min(1)->max((int) $field->getOption('max', 200)),
        ]);
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        $rows = array_values((array) ($value['rows'] ?? []));
        $width = count((array) ($rows[0] ?? []));
        foreach ($rows as $i => $row) {
            if (count((array) $row) !== $width) {
                return ['Row ' . ($i + 1) . ' has ' . count((array) $row) . ' cells; the first row has ' . $width . '. Every row needs the same number.'];
            }
        }

        return [];
    }

    /** Cells trimmed; rows kept in order. */
    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return [
            'head' => (bool) ($value['head'] ?? false),
            'rows' => array_map(static fn($row) => array_map(static fn($c) => trim((string) $c), array_values((array) $row)), array_values((array) ($value['rows'] ?? []))),
        ];
    }

    /** One line per row, cells between bars, so a change shows on its row. */
    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        return implode("\n", array_map(static fn($row) => implode(' | ', (array) $row), (array) ($value['rows'] ?? [])));
    }

    /** Does the table hold any text at all? (An untouched one doesn't count as filled in.) */
    public static function hasText(mixed $value): bool
    {
        foreach ((array) ($value['rows'] ?? []) as $row) {
            foreach ((array) $row as $cell) {
                if (trim((string) $cell) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
