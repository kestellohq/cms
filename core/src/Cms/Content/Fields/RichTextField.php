<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Support\Html;

/** HTML, sanitized with the "rich-text" profile when saved. The editor is replaceable (editor.richtext). */
final class RichTextField extends FieldType
{
    public function name(): string
    {
        return 'rich_text';
    }

    public function component(): string
    {
        return '@core/fields/RichText';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return Schema::string()->raw()->max(min(500000, (int) $field->getOption('max', 500000)));
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        $clean = Html::sanitize((string) $value, 'rich-text');

        return trim(Html::toText($clean)) === '' && !str_contains($clean, '<img') ? null : $clean;
    }

    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        return is_string($value) ? \Kestello\Support\Html::toText($value) : '';
    }
}
