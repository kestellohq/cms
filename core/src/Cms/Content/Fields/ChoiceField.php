<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use LogicException;

/** select and multi_select. Option "choices": value => label. */
final class ChoiceField extends FieldType
{
    public function __construct(private readonly string $type) {}

    public function name(): string
    {
        return $this->type;
    }

    public function component(): string
    {
        return $this->type === 'select' ? '@core/fields/Select' : '@core/fields/MultiSelect';
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        $values = array_map('strval', array_keys($this->choices($field)));
        $one = Schema::enum($values);

        return $this->type === 'select' ? $one : Schema::list($one)->max(count($values));
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return is_array($value) ? array_values(array_unique($value)) : $value;
    }

    public function index(mixed $value, Field $field): array
    {
        return array_map(static fn($v) => ['string' => (string) $v], is_array($value) ? $value : [$value]);
    }

    public function singleValued(Field $field): bool
    {
        return $this->type === 'select';
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        $choices = [];
        foreach ($this->choices($field) as $value => $label) {
            $choices[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return ['choices' => $choices] + array_diff_key($field->getOptions(), ['choices' => true]);
    }

    /** @return array<string, string> */
    private function choices(Field $field): array
    {
        $choices = $field->getOption('choices', []);
        if (!is_array($choices) || $choices === []) {
            throw new LogicException("Field [{$field->name}] needs choices.");
        }

        return $choices;
    }

    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        $choices = (array) $field->getOption('choices', []);

        return implode("\n", array_map(static fn($v) => (string) ($choices[$v] ?? $v), array_filter((array) $value, static fn($v) => $v !== null && $v !== '')));
    }
}
