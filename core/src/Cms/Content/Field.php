<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use InvalidArgumentException;

/**
 * A field of a content type:
 *
 *   Field::text('subtitle')->label('Subtitle')->max(160),
 *   Field::select('level', ['beginner' => 'Beginner', 'pro' => 'Pro'])->required()->indexed(),
 *   Field::repeater('speakers', [Field::text('name')->required(), Field::url('website')]),
 *
 * Values live in the entry's data JSON. indexed() copies a top-level
 * field into entry_index so lists can filter and sort by it.
 *
 * @api
 */
final class Field
{
    /**
     * Field types whose value is the same in every language unless a field
     * says otherwise (translatable()): a price, a date, a photo. Other
     * types (text, links to entries and terms, blocks, groups, plugin types)
     * are written per language.
     */
    public const array SHARED_TYPES = ['number', 'boolean', 'date', 'datetime', 'color', 'select', 'multi_select', 'media', 'user'];

    private string $label;
    private string $help = '';
    private bool $required = false;
    private bool $indexed = false;
    private bool $internal = false;
    private ?bool $translatable = null;
    private mixed $default = null;
    private string $width = 'full';
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var list<Field> */
    private array $children = [];

    public function __construct(public readonly string $name, public readonly string $type)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid field name [{$name}]: lowercase letters, digits and underscores.");
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $type) !== 1) {
            throw new InvalidArgumentException("Invalid field type [{$type}].");
        }
        $this->label = ucfirst(str_replace('_', ' ', $name));
    }

    public static function make(string $name, string $type): self
    {
        return new self($name, $type);
    }
    public static function text(string $name): self
    {
        return new self($name, 'text');
    }
    public static function textarea(string $name): self
    {
        return new self($name, 'textarea');
    }
    public static function richText(string $name): self
    {
        return new self($name, 'rich_text');
    }
    public static function number(string $name): self
    {
        return new self($name, 'number');
    }
    public static function boolean(string $name): self
    {
        return new self($name, 'boolean');
    }
    public static function date(string $name): self
    {
        return new self($name, 'date');
    }
    public static function datetime(string $name): self
    {
        return new self($name, 'datetime');
    }
    public static function email(string $name): self
    {
        return new self($name, 'email');
    }
    public static function url(string $name): self
    {
        return new self($name, 'url');
    }
    public static function color(string $name): self
    {
        return new self($name, 'color');
    }
    /** A site path, http(s) address, mailto: or tel: link. */
    public static function link(string $name): self
    {
        return new self($name, 'link');
    }
    /** Rows of plain-text cells, optionally with a heading row (Fields\TableField). */
    public static function table(string $name): self
    {
        return new self($name, 'table');
    }
    /** A block tree; $allowed limits the top-level block types. @param list<string>|null $allowed */
    public static function blocks(string $name, ?array $allowed = null): self
    {
        $field = new self($name, 'blocks');

        return $allowed === null ? $field : $field->option('allowed', $allowed);
    }

    /** @param array<string, string> $choices value => label */
    public static function select(string $name, array $choices): self
    {
        return (new self($name, 'select'))->option('choices', $choices);
    }

    /** @param array<string, string> $choices value => label */
    public static function multiSelect(string $name, array $choices): self
    {
        return (new self($name, 'multi_select'))->option('choices', $choices);
    }

    /** @param list<string> $types content types it may point at (empty: any) */
    public static function entry(string $name, array $types = [], bool $multiple = false): self
    {
        return (new self($name, 'entry'))->option('types', array_values($types))->option('multiple', $multiple);
    }

    public static function term(string $name, string $taxonomy, bool $multiple = false): self
    {
        return (new self($name, 'term'))->option('taxonomy', $taxonomy)->option('multiple', $multiple);
    }

    /** @param list<string> $kinds image, document, audio, video, archive (empty = any) */
    public static function media(string $name, array $kinds = [], bool $multiple = false): self
    {
        return (new self($name, 'media'))->option('kinds', array_values($kinds))->option('multiple', $multiple);
    }

    public static function image(string $name, bool $multiple = false): self
    {
        return self::media($name, ['image'], $multiple);
    }

    public static function user(string $name, bool $multiple = false): self
    {
        return (new self($name, 'user'))->option('multiple', $multiple);
    }

    /** @param list<Field> $fields */
    public static function group(string $name, array $fields): self
    {
        return (new self($name, 'group'))->children($fields);
    }

    /** @param list<Field> $fields */
    public static function repeater(string $name, array $fields): self
    {
        return (new self($name, 'repeater'))->children($fields);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }
    public function help(string $help): self
    {
        $this->help = $help;
        return $this;
    }
    /** Required when the entry is published or scheduled (drafts may be incomplete). */
    public function required(bool $required = true): self
    {
        $this->required = $required;
        return $this;
    }
    public function indexed(bool $indexed = true): self
    {
        $this->indexed = $indexed;
        return $this;
    }
    /** Leave this field's text out of site search (or put a non-text field in with true). */
    public function searchable(bool $on = true): self
    {
        return $this->option('searchable', $on);
    }

    /**
     * Written per language (true), or one value every translation shares
     * (false): saving it in one language saves it in all of them. Unset,
     * the type decides (SHARED_TYPES). Only top-level fields are shared.
     */
    public function translatable(bool $on = true): self
    {
        $this->translatable = $on;
        return $this;
    }

    /** Show the field in the editor's sidebar (featured images, short settings). */
    public function sidebar(): self
    {
        return $this->width('sidebar');
    }
    /** Editors only: left out of public pages and public API output. */
    public function internal(bool $internal = true): self
    {
        $this->internal = $internal;
        return $this;
    }
    public function default(mixed $value): self
    {
        $this->default = $value;
        return $this;
    }
    /** Editor layout: "full", "half", or "sidebar" (a box beside the content; top-level fields only). */
    public function width(string $width): self
    {
        if (!in_array($width, ['full', 'half', 'sidebar'], true)) {
            throw new InvalidArgumentException('Field width is "full", "half" or "sidebar".');
        }
        $this->width = $width;
        return $this;
    }
    public function min(int|float $min): self
    {
        return $this->option('min', $min);
    }
    public function max(int|float $max): self
    {
        return $this->option('max', $max);
    }
    public function placeholder(string $text): self
    {
        return $this->option('placeholder', $text);
    }

    /** A type-specific option (choices, min, max, rows, step, integer, multiple, types…). */
    public function option(string $key, mixed $value): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid field option [{$key}].");
        }
        $this->options[$key] = $value;
        return $this;
    }

    /** @param list<Field> $fields */
    public function children(array $fields): self
    {
        $names = [];
        foreach ($fields as $field) {
            if (!$field instanceof self) {
                throw new InvalidArgumentException("Fields of [{$this->name}] must be Field instances.");
            }
            if (isset($names[$field->name])) {
                throw new InvalidArgumentException("Duplicate field [{$field->name}] in [{$this->name}].");
            }
            $names[$field->name] = true;
        }
        $this->children = array_values($fields);
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
    public function getHelp(): string
    {
        return $this->help;
    }
    public function isRequired(): bool
    {
        return $this->required;
    }
    public function isIndexed(): bool
    {
        return $this->indexed;
    }
    /** Whether each language has its own value (see translatable()). */
    public function isTranslatable(): bool
    {
        return $this->translatable ?? !in_array($this->type, self::SHARED_TYPES, true);
    }

    /** translatable() as set: null when the type decides. */
    public function getTranslatable(): ?bool
    {
        return $this->translatable;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }
    public function getDefault(): mixed
    {
        return $this->default;
    }
    public function getWidth(): string
    {
        return $this->width;
    }
    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
    /** @return list<Field> */
    public function getChildren(): array
    {
        return $this->children;
    }
}
