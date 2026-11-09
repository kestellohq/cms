<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * A link target: a site path ("/about", "#top"), an http(s) address, mailto:
 * or tel:. Never javascript:, data: or other schemes.
 */
final class LinkField extends FieldType
{
    public function name(): string
    {
        return 'link';
    }

    public function component(): string
    {
        return '@core/fields/Link';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return Schema::string()->max(2048);
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        $v = (string) $value;

        return Html::safeUrl($v, ['http', 'https', 'mailto', 'tel']) && !str_starts_with($v, '//')
            ? [] : ['Use a path like /about, an http(s) address, mailto: or tel:.'];
    }

    public function defaultHelp(Field $field): ?string
    {
        return Translator::get('Type a page\'s title to find it, or an address such as /about or https://example.com.');
    }
}
