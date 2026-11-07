<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use DateTimeImmutable;
use DateTimeZone;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

/** date (YYYY-MM-DD) and datetime (stored as UTC ISO 8601). */
final class DateField extends FieldType
{
    public function __construct(private readonly string $type) {}

    public function name(): string
    {
        return $this->type;
    }

    public function component(): string
    {
        return '@core/fields/Date';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return Schema::string()->format($this->type === 'date' ? 'date' : 'date-time');
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return $this->type === 'date' ? $value : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function index(mixed $value, Field $field): array
    {
        $d = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

        return [['date' => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]];
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        return ['time' => $this->type === 'datetime'] + $field->getOptions();
    }

    public function indexKind(Field $field): string
    {
        return 'date';
    }
}
