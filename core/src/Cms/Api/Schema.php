<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use DateTimeImmutable;
use InvalidArgumentException;
use Kestello\Support\Translator;

/**
 * Input schema: validates request bodies and query strings, and describes
 * them in the OpenAPI document. Objects reject unknown keys, which blocks
 * mass assignment by construction.
 *
 *   Schema::object([
 *       'email' => Schema::string()->format('email')->max(255),
 *       'role'  => Schema::enum(['user', 'admin'])->optional(),
 *       'limit' => Schema::int()->min(1)->max(100)->default(25),
 *   ]);
 *
 * Instances are immutable: every modifier returns a copy.
 *
 * @api
 */
final class Schema
{
    private const array FORMATS = ['email', 'uri', 'date-time', 'date', 'uuid', 'slug', 'multiline'];   // multiline: forms show a text area

    /** @var array<string, Schema> */
    private array $properties = [];
    private ?Schema $items = null;
    /** @var list<string>|null */
    private ?array $values = null;

    /** @var array<string, string>|null labels for enum values (forms show them; validation ignores them) */
    private ?array $valueLabels = null;
    private bool $optional = false;
    private bool $nullable = false;
    private bool $hasDefault = false;
    private mixed $default = null;
    private int|float|null $min = null;
    private int|float|null $max = null;
    private ?string $format = null;
    private ?string $pattern = null;
    private string $description = '';
    private bool $trim = true;
    private bool $open = false;

    private function __construct(private readonly string $type) {}

    // ── Constructors ────────────────────────────────────────────────

    /** @param array<string, Schema> $properties */
    public static function object(array $properties = []): self
    {
        $schema = new self('object');
        foreach ($properties as $name => $property) {
            // Dotted/dashed names are for namespaced groups ("social-login.google").
            if (!$property instanceof self || !preg_match('/^[a-z_][a-z0-9_]*(?:[.-][a-z0-9_]+)*$/', (string) $name)) {
                throw new InvalidArgumentException("Invalid schema property [{$name}].");
            }
        }
        $schema->properties = $properties;

        return $schema;
    }

    /**
     * An object with free-form keys and scalar values (driver options,
     * metadata). Validate the contents in the endpoint.
     */
    public static function dictionary(int $maxKeys = 50): self
    {
        $schema = new self('object');
        $schema->open = true;
        $schema->max = $maxKeys;

        return $schema;
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function int(): self
    {
        return new self('integer');
    }

    public static function number(): self
    {
        return new self('number');
    }

    public static function bool(): self
    {
        return new self('boolean');
    }

    /**
     * @param list<string>|array<string, string> $values a list, or value => label for forms
     */
    public static function enum(array $values): self
    {
        $schema = new self('string');
        $labelled = !array_is_list($values);
        $schema->values = array_values(array_map('strval', $labelled ? array_keys($values) : $values));
        if ($labelled) {
            $schema->valueLabels = array_combine($schema->values, array_map('strval', array_values($values)));
        }

        return $schema;
    }

    /**
     * Any JSON value up to $maxBytes encoded and $maxDepth levels deep.
     * The endpoint validates the contents (e.g. entry data against its
     * content type).
     */
    public static function any(int $maxBytes = 262144, int $maxDepth = 12): self
    {
        $schema = new self('any');
        $schema->max = $maxBytes;
        $schema->min = $maxDepth;

        return $schema;
    }

    public static function list(Schema $items): self
    {
        $schema = new self('array');
        $schema->items = $items;

        return $schema;
    }

    // ── Modifiers ───────────────────────────────────────────────────

    public function optional(): self
    {
        return $this->with('optional', true);
    }

    public function nullable(): self
    {
        return $this->with('nullable', true);
    }

    public function default(mixed $value): self
    {
        $copy = $this->with('optional', true);
        $copy->hasDefault = true;
        $copy->default = $value;

        return $copy;
    }

    /**
     * PATCH shape of an object: every property optional and without a
     * default (absent keys stay absent), $nullable ones also accept null.
     *
     * @param list<string> $nullable
     */
    public function partial(array $nullable = []): self
    {
        $copy = $this->with('optional', true);
        foreach ($copy->properties as $name => $property) {
            $p = $property->with('optional', true);
            $p->hasDefault = false;
            $p->default = null;
            $copy->properties[$name] = in_array($name, $nullable, true) ? $p->with('nullable', true) : $p;
        }

        return $copy;
    }

    /** Minimum length (strings), value (numbers) or item count (lists). */
    public function min(int|float $min): self
    {
        return $this->with('min', $min);
    }

    public function max(int|float $max): self
    {
        return $this->with('max', $max);
    }

    /** An object schema with more properties (or some replaced). */
    public function extend(array $properties): self
    {
        if ($this->type !== 'object') {
            throw new InvalidArgumentException('Only object schemas can be extended.');
        }

        return $this->with('properties', [...$this->properties, ...$properties]);
    }

    public function format(string $format): self
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown format [{$format}].");
        }

        return $this->with('format', $format);
    }

    /** A regular expression without delimiters, matched against the whole value. */
    public function pattern(string $pattern): self
    {
        return $this->with('pattern', $pattern);
    }

    public function description(string $description): self
    {
        return $this->with('description', $description);
    }

    /** Keep surrounding whitespace in strings (trimmed by default). */
    public function raw(): self
    {
        return $this->with('trim', false);
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, Schema> */
    public function properties(): array
    {
        return $this->properties;
    }

    // ── Validation ──────────────────────────────────────────────────

    /**
     * Validate (and normalise) a value. With $coerce, strings from a query
     * string are converted to ints/numbers/booleans first.
     *
     * @return array{0: mixed, 1: array<string, list<string>>} [clean value, errors by dot path]
     */
    public function validate(mixed $value, bool $coerce = false): array
    {
        $errors = [];
        $clean = $this->check($value, '', $coerce, $errors);

        return [$clean, $errors];
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function check(mixed $value, string $path, bool $coerce, array &$errors): mixed
    {
        if ($value === null) {
            if (!$this->nullable) {
                $errors[$path ?: '_'][] = Translator::get('This field needs a value.');
            }
            return null;
        }

        return match ($this->type) {
            'object' => $this->checkObject($value, $path, $coerce, $errors),
            'array' => $this->checkList($value, $path, $coerce, $errors),
            'string' => $this->checkString($value, $path, $errors),
            'integer', 'number' => $this->checkNumber($value, $path, $coerce, $errors),
            'boolean' => $this->checkBool($value, $path, $coerce, $errors),
            'any' => $this->checkAny($value, $path, $errors),
        };
    }

    /** @param array<string, list<string>> $errors */
    private function checkAny(mixed $value, string $path, array &$errors): mixed
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR, (int) $this->min);
        } catch (\JsonException) {
            $errors[$path ?: '_'][] = Translator::get('Too deeply nested or not valid JSON.');
            return null;
        }
        if (strlen($json) > (int) $this->max) {
            $errors[$path ?: '_'][] = Translator::get('Too large.');
            return null;
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    private function checkObject(mixed $value, string $path, bool $coerce, array &$errors): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[$path ?: '_'][] = Translator::get('This field must be an object.');
            return null;
        }

        $clean = [];

        if ($this->open) {
            if ($this->max !== null && count($value) > $this->max) {
                $errors[$path ?: '_'][] = Translator::get('Must contain at most {max} keys.', ['max' => (string) $this->max]);
                return null;
            }
            foreach ($value as $key => $v) {
                $childPath = self::join($path, (string) $key);
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/', (string) $key) !== 1) {
                    $errors[$childPath][] = Translator::get('Invalid key.');
                } elseif ($v !== null && !is_scalar($v)) {
                    $errors[$childPath][] = Translator::get('Values must be strings, numbers, booleans or null.');
                } elseif (is_string($v) && strlen($v) > 4096) {
                    $errors[$childPath][] = Translator::get('Value is too long.');
                } else {
                    $clean[(string) $key] = $v;
                }
            }
            return $clean;
        }

        foreach ($value as $key => $_) {
            if (!isset($this->properties[$key])) {
                $errors[self::join($path, (string) $key)][] = Translator::get('Unknown field.');
            }
        }

        foreach ($this->properties as $name => $property) {
            $childPath = self::join($path, $name);

            if (!array_key_exists($name, $value)) {
                if ($property->hasDefault) {
                    $clean[$name] = $property->default;
                } elseif (!$property->optional) {
                    $errors[$childPath][] = Translator::get('This field is required.');
                }
                continue;
            }

            $clean[$name] = $property->check($value[$name], $childPath, $coerce, $errors);
        }

        return $clean;
    }

    /** @param array<string, list<string>> $errors */
    private function checkList(mixed $value, string $path, bool $coerce, array &$errors): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[$path ?: '_'][] = Translator::get('This field must be a list.');
            return null;
        }

        if ($this->min !== null && count($value) < $this->min) {
            $errors[$path ?: '_'][] = Translator::get('Must contain at least {min} items.', ['min' => (string) $this->min]);
        }
        if ($this->max !== null && count($value) > $this->max) {
            $errors[$path ?: '_'][] = Translator::get('Must contain at most {max} items.', ['max' => (string) $this->max]);
            return null; // don't validate an oversized list item by item
        }

        $clean = [];
        foreach ($value as $i => $item) {
            $clean[] = $this->items?->check($item, self::join($path, (string) $i), $coerce, $errors);
        }

        return $clean;
    }

    /** @param array<string, list<string>> $errors */
    private function checkString(mixed $value, string $path, array &$errors): ?string
    {
        $key = $path ?: '_';

        if (!is_string($value)) {
            $errors[$key][] = Translator::get('This field must be a string.');
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors[$key][] = Translator::get('This field must be valid UTF-8.');
            return null;
        }

        if ($this->trim) {
            $value = trim($value);
        }

        if ($this->values !== null) {
            if (!in_array($value, $this->values, true)) {
                $errors[$key][] = Translator::get('Must be one of: {values}.', ['values' => implode(', ', $this->values)]);
            }
            return $value;
        }

        $length = mb_strlen($value);
        if ($this->min !== null && $length < $this->min) {
            $errors[$key][] = $this->min === 1 ? Translator::get('This field must not be empty.') : Translator::get('Must be at least {min} characters.', ['min' => (string) $this->min]);
        }
        if ($this->max !== null && $length > $this->max) {
            $errors[$key][] = Translator::get('Must be at most {max} characters.', ['max' => (string) $this->max]);
        }

        if ($value !== '' && $this->format !== null && !self::matchesFormat($value, $this->format)) {
            $errors[$key][] = match ($this->format) {
                'email' => Translator::get('Must be a valid email address.'),
                'uri' => Translator::get('Must be a valid http(s) URL.'),
                'date-time' => Translator::get('Must be an ISO 8601 date-time.'),
                'date' => Translator::get('Must be a date (YYYY-MM-DD).'),
                'uuid' => Translator::get('Must be a UUID.'),
                'slug' => Translator::get('Use lowercase letters, numbers and dashes.'),
            };
        }

        if ($this->pattern !== null && preg_match('~^(?:' . str_replace('~', '\~', $this->pattern) . ')$~u', $value) !== 1) {
            $errors[$key][] = Translator::get('Has an invalid format.');
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    private function checkNumber(mixed $value, string $path, bool $coerce, array &$errors): int|float|null
    {
        $key = $path ?: '_';

        if ($coerce && is_string($value)) {
            if ($this->type === 'integer' && preg_match('/^-?\d{1,18}$/', $value)) {
                $value = (int) $value;
            } elseif ($this->type === 'number' && is_numeric($value)) {
                $value = (float) $value;
            }
        }

        $ok = $this->type === 'integer' ? is_int($value) : (is_int($value) || is_float($value));
        if (!$ok) {
            $errors[$key][] = $this->type === 'integer' ? Translator::get('This field must be an integer.') : Translator::get('This field must be a number.');
            return null;
        }

        if ($this->min !== null && $value < $this->min) {
            $errors[$key][] = Translator::get('Must be at least {min}.', ['min' => (string) $this->min]);
        }
        if ($this->max !== null && $value > $this->max) {
            $errors[$key][] = Translator::get('Must be at most {max}.', ['max' => (string) $this->max]);
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    private function checkBool(mixed $value, string $path, bool $coerce, array &$errors): ?bool
    {
        if ($coerce && is_string($value)) {
            $value = match (strtolower($value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => $value,
            };
        }

        if (!is_bool($value)) {
            $errors[$path ?: '_'][] = Translator::get('This field must be true or false.');
            return null;
        }

        return $value;
    }

    private static function matchesFormat(string $value, string $format): bool
    {
        return match ($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true),
            'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1
                && self::parses($value),
            'date' => ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) !== false && $d->format('Y-m-d') === $value,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            'slug' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1,
            'multiline' => true,
        };
    }

    private static function parses(string $value): bool
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        // Reject overflowed dates such as 2026-02-31 (PHP rolls them over).
        return $date->format('Y-m-d') === substr($value, 0, 10);
    }

    // ── OpenAPI ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function toOpenApi(): array
    {
        if ($this->type === 'any') {
            return $this->description !== '' ? ['description' => $this->description] : [];
        }
        $out = ['type' => $this->type];

        if ($this->type === 'object' && $this->open) {
            $out['additionalProperties'] = ['type' => ['string', 'number', 'boolean', 'null']];
            if ($this->max !== null) {
                $out['maxProperties'] = $this->max;
            }
        } elseif ($this->type === 'object') {
            $out['additionalProperties'] = false;
            $out['properties'] = $this->properties === [] ? (object) [] : array_map(static fn(self $p): array => $p->toOpenApi(), $this->properties);
            $required = array_keys(array_filter($this->properties, static fn(self $p): bool => !$p->optional));
            if ($required !== []) {
                $out['required'] = $required;
            }
        } elseif ($this->type === 'array') {
            $out['items'] = $this->items?->toOpenApi() ?? (object) [];
            if ($this->min !== null) {
                $out['minItems'] = $this->min;
            }
            if ($this->max !== null) {
                $out['maxItems'] = $this->max;
            }
        } elseif ($this->type === 'string') {
            if ($this->values !== null) {
                $out['enum'] = $this->values;
            }
            if ($this->valueLabels !== null) {
                $out['x-enum-labels'] = $this->valueLabels;
            }
            if ($this->min !== null) {
                $out['minLength'] = $this->min;
            }
            if ($this->max !== null) {
                $out['maxLength'] = $this->max;
            }
            if ($this->format !== null && $this->format !== 'slug') {
                $out['format'] = $this->format;
            }
            if ($this->format === 'slug') {
                $out['pattern'] = '^[a-z0-9]+(?:-[a-z0-9]+)*$';
            }
            if ($this->pattern !== null) {
                $out['pattern'] = '^(?:' . $this->pattern . ')$';
            }
        } elseif ($this->type === 'integer' || $this->type === 'number') {
            if ($this->min !== null) {
                $out['minimum'] = $this->min;
            }
            if ($this->max !== null) {
                $out['maximum'] = $this->max;
            }
        }

        if ($this->nullable) {
            $out['nullable'] = true;
        }
        if ($this->hasDefault) {
            $out['default'] = $this->default;
        }
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }

        return $out;
    }

    // ── Internal ────────────────────────────────────────────────────

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;

        return $copy;
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }
}
