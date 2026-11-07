<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Support\Html;

/** text, textarea, email, url, color. Options: min, max, placeholder, rows (textarea). */
final class TextField extends FieldType
{
    public function __construct(
        private readonly string $type,
        private readonly int $maxLength,
        private readonly bool $multiline = false,
        private readonly ?string $format = null,
        private readonly ?string $pattern = null,
        private readonly ?int $ceiling = null,
    ) {}

    /**
     * The longest value the field takes: its own max when set, else the
     * type's ($maxLength); a field may go above that up to $ceiling, so a
     * caption or alt text can say more than a title.
     */
    private function max(Field $field): int
    {
        return max(0, min($this->ceiling ?? $this->maxLength, (int) $field->getOption('max', $this->maxLength)));
    }

    public function name(): string
    {
        return $this->type;
    }

    public function component(): string
    {
        return $this->multiline ? '@core/fields/Textarea' : ($this->type === 'color' ? '@core/fields/Color' : '@core/fields/Text');
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        $s = Schema::string()->max($this->max($field));
        if (($min = $field->getOption('min')) !== null) {
            $s = $s->min((int) $min);
        }
        if ($this->multiline) {
            $s = $s->raw();
        }
        if ($this->format !== null) {
            $s = $s->format($this->format);
        }
        if ($this->pattern !== null) {
            $s = $s->pattern($this->pattern);
        }

        return $s;
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        return $this->type === 'url' && !Html::safeUrl((string) $value, ['http', 'https']) ? ['Use an http(s) address.'] : [];
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        return $this->type === 'color' ? strtolower((string) $value) : $value;
    }

    public function index(mixed $value, Field $field): array
    {
        return [['string' => mb_substr((string) $value, 0, 191)]];
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        return ['max' => $this->max($field), 'input' => match ($this->type) {
            'email' => 'email', 'url' => 'url', default => 'text',
        }] + $field->getOptions();
    }
}
