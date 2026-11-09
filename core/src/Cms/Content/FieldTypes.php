<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use InvalidArgumentException;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\I18n\Labels;
use Kestello\Framework\Core\ExecutionContext;
use LogicException;

/**
 * The field type registry ($cms->content->fields).
 *
 * @api
 */
final class FieldTypes
{
    /** @var array<string, array{type: FieldType, owner: string}> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            new Fields\TextField('text', 255, ceiling: 2000), new Fields\TextField('textarea', 20000, multiline: true),
            new Fields\TextField('email', 255, format: 'email'), new Fields\TextField('url', 2048, format: 'uri'),
            new Fields\TextField('color', 7, pattern: '#[0-9a-fA-F]{6}'),
            new Fields\RichTextField(), new Fields\NumberField(), new Fields\BooleanField(),
            new Fields\ChoiceField('select'), new Fields\ChoiceField('multi_select'),
            new Fields\DateField('date'), new Fields\DateField('datetime'),
            new Fields\EntryField(), new Fields\TermField(), new Fields\MediaField(), new Fields\UserField(), new Fields\GroupField(), new Fields\RepeaterField(),
            new Fields\BlocksField(), new Fields\LinkField(), new Fields\TableField(),
        ] as $type) {
            $this->types[$type->name()] = ['type' => $type, 'owner' => 'core'];
        }
    }

    public function register(FieldType $type): void
    {
        $owner = ExecutionContext::current();
        $name = $type->name();
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid field type name [{$name}].");
        }
        if (str_starts_with($owner, 'plugin:') || str_starts_with($owner, 'theme:')) {
            $slug = explode(':', $owner, 2)[1];
            if (!str_starts_with($name, $slug . '.')) {
                throw new InvalidArgumentException("Field types of {$owner} must be named \"{$slug}.…\".");
            }
        }
        if (isset($this->types[$name])) {
            throw new LogicException("Field type [{$name}] is already registered by {$this->types[$name]['owner']}.");
        }
        $this->types[$name] = ['type' => $type, 'owner' => $owner];
    }

    public function get(string $name): FieldType
    {
        return $this->types[$name]['type'] ?? throw new LogicException("Unknown field type [{$name}] (is its plugin active?).");
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /** Schema of a field's value including null handling ($draft: nothing required). */
    public function schemaFor(Field $field, bool $draft): Schema
    {
        $schema = $this->get($field->type)->schema($field, $this);

        return $field->isRequired() && !$draft ? $schema : $schema->optional()->nullable();
    }

    /**
     * @param list<Field> $fields
     */
    public function objectSchema(array $fields, bool $draft): Schema
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field->name] = $this->schemaFor($field, $draft);
        }

        return Schema::object($properties);
    }

    /**
     * Fields as "Label: value" lines, for comparing revisions of a group or
     * a repeater row. A value of several lines continues indented.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $data
     */
    public function text(array $fields, array $data): string
    {
        $lines = [];
        foreach ($fields as $field) {
            if (!$this->has($field->type)) {
                continue;
            }
            $text = $this->get($field->type)->text($data[$field->name] ?? null, $field, $this);
            if ($text !== '') {
                $lines[] = $field->getLabel() . ': ' . (str_contains($text, "\n") ? "\n  " . str_replace("\n", "\n  ", $text) : $text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Run check() and normalize() over validated data.
     *
     * @param list<Field> $fields
     * @param array<string, list<string>> $errors
     * @return array<string, mixed>
     */
    public function finish(array $fields, array $data, string $path, array &$errors): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field->name, $data)) {
                continue;
            }
            $value = $data[$field->name];
            if ($value === null || $value === '' || $value === []) {
                $out[$field->name] = $value === '' ? null : $value;
                continue;
            }
            $type = $this->get($field->type);
            foreach ($type->check($value, $field, $this) as $message) {
                $errors[$path . $field->name][] = $message;
            }
            $out[$field->name] = $type->normalize($value, $field, $this);
        }

        return $out;
    }

    /**
     * Media ids used by $data, per top-level field.
     *
     * @param list<Field>          $fields
     * @param array<string, mixed> $data
     * @return array<string, list<int>>
     */
    public function mediaIn(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $data[$field->name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $ids = $this->get($field->type)->media($value, $field, $this);
            if ($ids !== []) {
                $out[$field->name] = $ids;
            }
        }

        return $out;
    }

    /**
     * Entry ids each top-level field links to, at any depth (see FieldType::relations()).
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $data
     * @return array<string, list<int>>
     */
    public function relationsIn(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $data[$field->name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $ids = $this->get($field->type)->relations($value, $field, $this);
            if ($ids !== []) {
                $out[$field->name] = $ids;
            }
        }

        return $out;
    }

    /**
     * For the admin. With a domain (the block's or content type's owner), labels,
     * help and choice labels are translated; without one they go as written.
     *
     * @param list<Field> $fields
     * @return list<array<string, mixed>>
     */
    public function describe(array $fields, ?string $domain = null): array
    {
        return array_map(function (Field $f) use ($domain): array {
            $type = $this->get($f->type);
            $options = $type->describe($f, $this);
            if ($domain !== null && isset($options['choices']) && is_array($options['choices'])) {
                $options['choices'] = Labels::choices($options['choices'], $domain);
            }

            return [
                'name' => $f->name,
                'type' => $f->type,
                'label' => $domain === null ? $f->getLabel() : Labels::text($f->getLabel(), $domain),
                'help' => $f->getHelp() === '' ? $type->defaultHelp($f) : ($domain === null ? $f->getHelp() : Labels::text((string) $f->getHelp(), $domain)),
                'required' => $f->isRequired(),
                'indexed' => $f->isIndexed(),
                'internal' => $f->isInternal(),
                'default' => $f->getDefault(),
                'width' => $f->getWidth(),
                'component' => $type->component(),
                'options' => (object) $options,
                'fields' => $this->describe($f->getChildren(), $domain),
                'conditions' => $f->getOption('conditions'),
            ];
        }, $fields);
    }
}
