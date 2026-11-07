<?php

declare(strict_types=1);

namespace Kestello\Framework\Validation;

use Closure;
use InvalidArgumentException;
use Kestello\Contracts\Validation\ImplicitRule;
use Kestello\Contracts\Validation\Rule;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Arr;
use Kestello\Support\Color;
use Kestello\Support\Date;
use Kestello\Support\Mime;
use Kestello\Support\Translator;

/**
 * Validates input with Laravel-style rules.
 *
 *   $v = Validator::make($input, [
 *       'title'         => 'required|string|max:200',
 *       'email'         => ['required', 'email', 'unique:users,email'],
 *       'age'           => 'nullable|integer|min:18',
 *       'tags'          => 'array|max:10',
 *       'tags.*'        => 'string|distinct',
 *       'items.*.qty'   => 'required|integer|gt:0',
 *       'starts_at'     => 'date|after:now',
 *       'ends_at'       => 'date|after:starts_at',
 *       'photo'         => 'file|image|max:2048',       // kB
 *       'isbn'          => ['required', new IsbnRule()],
 *   ]);
 *
 * Fields that are missing, null or "" only run "implicit" rules (required*,
 * accepted, present, filled, prohibited*); everything else is skipped, so
 * optional fields need no "nullable". min/max/size/between measure numbers
 * when the field has a numeric or integer rule, files in kilobytes, arrays
 * by count and strings by characters.
 *
 * Messages are English templates translated through the Translator (core
 * domain); override per "field.rule", "field.*.rule" or "rule".
 */
final class Validator
{
    private const array IMPLICIT = ['required', 'required_if', 'required_unless', 'required_with', 'required_without', 'accepted', 'present', 'filled', 'prohibited', 'prohibited_if'];

    private const array MESSAGES = [
        'required' => 'The {attribute} field is required.',
        'required_if' => 'The {attribute} field is required when {other} is {value}.',
        'required_unless' => 'The {attribute} field is required unless {other} is {value}.',
        'required_with' => 'The {attribute} field is required when {other} is present.',
        'required_without' => 'The {attribute} field is required when {other} is not present.',
        'present' => 'The {attribute} field must be present.',
        'filled' => 'The {attribute} field must have a value.',
        'prohibited' => 'The {attribute} field is not allowed.',
        'prohibited_if' => 'The {attribute} field is not allowed when {other} is {value}.',
        'accepted' => 'The {attribute} must be accepted.',
        'string' => 'The {attribute} must be a string.',
        'email' => 'The {attribute} must be a valid email address.',
        'url' => 'The {attribute} must be a valid URL.',
        'numeric' => 'The {attribute} must be a number.',
        'integer' => 'The {attribute} must be an integer.',
        'boolean' => 'The {attribute} must be true or false.',
        'array' => 'The {attribute} must be a list or group of values.',
        'list' => 'The {attribute} must be a list.',
        'alpha' => 'The {attribute} may only contain letters.',
        'alpha_num' => 'The {attribute} may only contain letters and numbers.',
        'alpha_dash' => 'The {attribute} may only contain letters, numbers, dashes, and underscores.',
        'slug' => 'The {attribute} may only contain lowercase letters, numbers and dashes.',
        'min.string' => 'The {attribute} must be at least {min} characters.',
        'min.numeric' => 'The {attribute} must be at least {min}.',
        'min.array' => 'The {attribute} must have at least {min} items.',
        'min.file' => 'The {attribute} must be at least {min} kilobytes.',
        'max.string' => 'The {attribute} must not exceed {max} characters.',
        'max.numeric' => 'The {attribute} must not exceed {max}.',
        'max.array' => 'The {attribute} must not have more than {max} items.',
        'max.file' => 'The {attribute} must not be larger than {max} kilobytes.',
        'size.string' => 'The {attribute} must be {size} characters.',
        'size.numeric' => 'The {attribute} must be {size}.',
        'size.array' => 'The {attribute} must contain {size} items.',
        'size.file' => 'The {attribute} must be {size} kilobytes.',
        'between.string' => 'The {attribute} must be between {min} and {max} characters.',
        'between.numeric' => 'The {attribute} must be between {min} and {max}.',
        'between.array' => 'The {attribute} must have between {min} and {max} items.',
        'between.file' => 'The {attribute} must be between {min} and {max} kilobytes.',
        'gt' => 'The {attribute} must be greater than {value}.',
        'gte' => 'The {attribute} must be at least {value}.',
        'lt' => 'The {attribute} must be less than {value}.',
        'lte' => 'The {attribute} must not be greater than {value}.',
        'digits' => 'The {attribute} must be {digits} digits.',
        'digits_between' => 'The {attribute} must be between {min} and {max} digits.',
        'in' => 'The selected {attribute} is invalid.',
        'not_in' => 'The selected {attribute} is invalid.',
        'regex' => 'The {attribute} format is invalid.',
        'not_regex' => 'The {attribute} format is invalid.',
        'starts_with' => 'The {attribute} must start with one of: {values}.',
        'ends_with' => 'The {attribute} must end with one of: {values}.',
        'lowercase' => 'The {attribute} must be lowercase.',
        'uppercase' => 'The {attribute} must be uppercase.',
        'confirmed' => 'The {attribute} confirmation does not match.',
        'same' => 'The {attribute} and {other} must match.',
        'different' => 'The {attribute} and {other} must be different.',
        'distinct' => 'The {attribute} has a duplicate value.',
        'unique' => 'The {attribute} has already been taken.',
        'exists' => 'The selected {attribute} is invalid.',
        'date' => 'The {attribute} is not a valid date.',
        'date_format' => 'The {attribute} does not match the format {format}.',
        'after' => 'The {attribute} must be a date after {date}.',
        'after_or_equal' => 'The {attribute} must be a date after or equal to {date}.',
        'before' => 'The {attribute} must be a date before {date}.',
        'before_or_equal' => 'The {attribute} must be a date before or equal to {date}.',
        'timezone' => 'The {attribute} must be a valid timezone.',
        'uuid' => 'The {attribute} must be a valid UUID.',
        'ulid' => 'The {attribute} must be a valid ULID.',
        'json' => 'The {attribute} must be valid JSON.',
        'ip' => 'The {attribute} must be a valid IP address.',
        'ipv4' => 'The {attribute} must be a valid IPv4 address.',
        'ipv6' => 'The {attribute} must be a valid IPv6 address.',
        'hex_color' => 'The {attribute} must be a valid hexadecimal colour.',
        'color' => 'The {attribute} must be a valid colour.',
        'file' => 'The {attribute} must be an uploaded file.',
        'image' => 'The {attribute} must be an image (JPEG, PNG, GIF, WebP or AVIF).',
        'mimes' => 'The {attribute} must be a file of type: {values}.',
        'mimetypes' => 'The {attribute} must be a file of type: {values}.',
        'dimensions' => 'The {attribute} has invalid image dimensions.',
        'invalid' => 'The {attribute} is invalid.',
    ];

    /** @var array<string, array{check: Closure, message: string, implicit: bool}> */
    private static array $extensions = [];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * @param array<string, mixed>                          $data
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @param array<string, string>                         $messages
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
    ) {
        $this->validate();
    }

    /**
     * @param array<string, mixed>                          $data
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @param array<string, string>                         $messages
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    /**
     * Add a named rule for everyone (plugins). The check gets the value,
     * the rule's parameters, all data and the attribute path.
     *
     * @param Closure(mixed $value, list<string> $params, array<string, mixed> $data, string $attribute): bool $check
     */
    public static function extend(string $name, Closure $check, string $message = 'The {attribute} is invalid.', bool $implicit = false): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid rule name \"{$name}\".");
        }
        if (self::isBuiltIn($name)) {
            throw new InvalidArgumentException("\"{$name}\" is a built-in rule.");
        }
        self::$extensions[$name] = ['check' => $check, 'message' => $message, 'implicit' => $implicit];
    }

    /** @internal tests */
    public static function forgetExtensions(): void
    {
        self::$extensions = [];
    }

    /** @param array<string, string> $aliases field (or "items.*.name") => display name */
    public function setAliases(array $aliases): self
    {
        $this->aliases = $aliases;
        $this->errors = [];
        $this->validated = [];
        $this->validate();

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> path => messages */
    public function errors(): array
    {
        return $this->errors;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /** Validated fields only (nested for dotted rules). @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return Arr::get($this->validated, $field, $default);
    }

    /** @throws ValidationException */
    public function validateOrFail(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    // ── engine ───────────────────────────────────────────────────

    private function validate(): void
    {
        foreach ($this->rules as $pattern => $spec) {
            $rules = $this->parse($spec);
            foreach ($this->expand((string) $pattern) as $path) {
                $this->validateField($path, (string) $pattern, $rules);
            }
        }
    }

    /**
     * @param list<array{0: string|Rule|Closure, 1: list<string>}> $rules
     */
    private function validateField(string $path, string $pattern, array $rules): void
    {
        $names = array_map(static fn(array $r) => is_string($r[0]) ? $r[0] : '', $rules);
        $present = $this->present($path);
        $value = $present ? Arr::get($this->data, $path) : null;
        $empty = self::isEmpty($value) && !($value === [] && in_array('array', $names, true)); // an empty upload counts as missing

        if (in_array('sometimes', $names, true) && !$present) {
            return;
        }
        $bail = in_array('bail', $names, true);

        foreach ($rules as [$rule, $params]) {
            if (in_array($rule, ['nullable', 'sometimes', 'bail'], true)) {
                continue;
            }
            $implicit = (is_string($rule) && (in_array($rule, self::IMPLICIT, true) || (self::$extensions[$rule]['implicit'] ?? false))) || $rule instanceof ImplicitRule;
            if ($empty && !$implicit) {
                continue;
            }
            $before = count($this->errors[$path] ?? []);
            $this->apply($path, $pattern, $value, $rule, $params, $names);
            if ($bail && count($this->errors[$path] ?? []) > $before) {
                break;
            }
        }

        if (!isset($this->errors[$path]) && ($present || !str_contains($pattern, '*'))) {
            $this->validated = Arr::set($this->validated, $path, $value);
        }
    }

    /**
     * @param list<string> $params
     * @param list<string> $names all rule names on the field
     */
    private function apply(string $path, string $pattern, mixed $value, string|Rule|Closure $rule, array $params, array $names): void
    {
        $attribute = $this->attributeName($path, $pattern);
        if ($rule instanceof Rule || $rule instanceof Closure) {
            $fail = function (string $message, array $params = [], string $domain = 'core') use ($path, $attribute): void {
                $this->errors[$path][] = Translator::get($message, ['attribute' => $attribute] + $params, $domain);
            };
            $rule instanceof Rule ? $rule->validate($path, $value, $fail, $this->data) : $rule($path, $value, $fail, $this->data);
            return;
        }

        $numeric = in_array('numeric', $names, true) || in_array('integer', $names, true);
        $kind = $this->sizeKind($value, $numeric);
        $other = fn(int $i = 0) => Arr::get($this->data, $this->sibling($path, $params[$i] ?? ''));

        $passed = match ($rule) {
            'required' => !self::isEmpty($value),
            'required_if' => !in_array((string) self::str($other()), array_slice($params, 1), true) || !self::isEmpty($value),
            'required_unless' => in_array((string) self::str($other()), array_slice($params, 1), true) || !self::isEmpty($value),
            'required_with' => $this->allEmpty($path, $params) || !self::isEmpty($value),
            'required_without' => !$this->anyEmpty($path, $params) || !self::isEmpty($value),
            'present' => $this->present($path),
            'filled' => !$this->present($path) || !self::isEmpty($value),
            'prohibited' => self::isEmpty($value),
            'prohibited_if' => !in_array((string) self::str($other()), array_slice($params, 1), true) || self::isEmpty($value),
            'accepted' => in_array($value, ['yes', 'on', '1', 1, true, 'true'], true),
            'string' => is_string($value),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), $params !== [] ? $params : ['http', 'https'], true),
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array' => is_array($value) && ($params === [] || array_diff(array_keys($value), $params) === []),
            'list' => is_array($value) && array_is_list($value),
            'alpha' => is_string($value) && preg_match('/^[\pL\pM]+$/u', $value) === 1,
            'alpha_num' => (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN]+$/u', (string) $value) === 1,
            'alpha_dash' => (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN_-]+$/u', (string) $value) === 1,
            'slug' => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1,
            'min' => $kind !== null && $this->measure($value, $kind) >= (float) ($params[0] ?? 0),
            'max' => $kind !== null && $this->measure($value, $kind) <= (float) ($params[0] ?? PHP_INT_MAX),
            'size' => $kind !== null && $this->measure($value, $kind) == (float) ($params[0] ?? 0),
            'between' => $kind !== null && $this->measure($value, $kind) >= (float) ($params[0] ?? 0) && $this->measure($value, $kind) <= (float) ($params[1] ?? 0),
            'gt', 'gte', 'lt', 'lte' => $this->compare($value, $rule, $this->operand($path, $params[0] ?? '', $kind), $kind),
            'digits' => is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) === (int) ($params[0] ?? 0),
            'digits_between' => is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) >= (int) ($params[0] ?? 0) && strlen((string) $value) <= (int) ($params[1] ?? 0),
            'in' => is_scalar($value) && in_array((string) self::str($value), $params, true),
            'not_in' => !is_scalar($value) || !in_array((string) self::str($value), $params, true),
            'regex' => is_scalar($value) && @preg_match($this->pattern($params), (string) $value) === 1,
            'not_regex' => is_scalar($value) && @preg_match($this->pattern($params), (string) $value) === 0,
            'starts_with' => is_string($value) && array_filter($params, static fn($p) => str_starts_with($value, $p)) !== [],
            'ends_with' => is_string($value) && array_filter($params, static fn($p) => str_ends_with($value, $p)) !== [],
            'lowercase' => is_string($value) && mb_strtolower($value) === $value,
            'uppercase' => is_string($value) && mb_strtoupper($value) === $value,
            'confirmed' => $value === (Arr::get($this->data, $path . '_confirmation') ?? Arr::get($this->data, $path . '_confirm')),
            'same' => self::str($value) === self::str($other()),
            'different' => self::str($value) !== self::str($other()),
            'distinct' => $this->isDistinct($path, $pattern, $value),
            'unique' => $this->ruleUnique($value, $params),
            'exists' => $this->ruleExists($value, $params),
            'date' => self::date($value) !== null,
            'date_format' => is_string($value) && ($d = \DateTimeImmutable::createFromFormat('!' . ($params[0] ?? 'Y-m-d'), $value)) !== false && $d->format($params[0] ?? 'Y-m-d') === $value,
            'after', 'after_or_equal', 'before', 'before_or_equal' => $this->compareDates($value, $rule, $path, $params[0] ?? 'now'),
            'timezone' => is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true),
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            'ulid' => is_string($value) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $value) === 1,
            'json' => is_string($value) && json_validate($value),
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'hex_color' => is_string($value) && preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1,
            'color' => is_string($value) && Color::isValid($value),
            'file' => self::file($value) !== null,
            'image' => ($f = self::file($value)) !== null && in_array(Mime::detect($f['tmp_name']), ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true),
            'mimes' => ($f = self::file($value)) !== null && $this->mimes($f, $params),
            'mimetypes' => ($f = self::file($value)) !== null && $this->mimetypes($f, $params),
            'dimensions' => ($f = self::file($value)) !== null && $this->dimensions($f, $params),
            default => $this->extension($rule, $value, $params, $path),
        };

        if (!$passed) {
            $this->addError($path, $pattern, $rule, $params, $kind ?? 'string');
        }
    }

    private function extension(string $rule, mixed $value, array $params, string $path): bool
    {
        $ext = self::$extensions[$rule] ?? throw new InvalidArgumentException("Unknown validation rule \"{$rule}\".");

        return (bool) ($ext['check'])($value, $params, $this->data, $path);
    }

    /** @param list<string> $params */
    private function addError(string $path, string $pattern, string $rule, array $params, string $kind): void
    {
        $custom = $this->messages["{$path}.{$rule}"] ?? $this->messages["{$pattern}.{$rule}"] ?? $this->messages[$rule] ?? null;
        $sized = in_array($rule, ['min', 'max', 'size', 'between'], true) ? "{$rule}.{$kind}" : $rule;
        $template = $custom ?? self::MESSAGES[$sized] ?? self::$extensions[$rule]['message'] ?? self::MESSAGES['invalid'];

        $other = $params[0] ?? '';
        $replace = [
            'attribute' => $this->attributeName($path, $pattern),
            'other' => $this->aliases[$other] ?? str_replace('_', ' ', $other),
            'value' => in_array($rule, ['gt', 'gte', 'lt', 'lte'], true) && !is_numeric($other) ? ($this->aliases[$other] ?? str_replace('_', ' ', $other)) : (string) ($params[1] ?? $other),
            'values' => implode(', ', $params),
            'min' => (string) ($params[0] ?? ''),
            'max' => (string) ($rule === 'between' || $rule === 'digits_between' ? ($params[1] ?? '') : ($params[0] ?? '')),
            'size' => (string) ($params[0] ?? ''),
            'digits' => (string) ($params[0] ?? ''),
            'format' => (string) ($params[0] ?? ''),
            'date' => $this->aliases[$other] ?? str_replace('_', ' ', $other),
        ];
        if (in_array($rule, ['gt', 'gte', 'lt', 'lte'], true)) {
            $replace['value'] = is_numeric($other) ? $other : $replace['other'];
        }

        $this->errors[$path][] = $custom !== null
            ? strtr($custom, array_combine(array_map(static fn($k) => '{' . $k . '}', array_keys($replace)), array_values($replace)))
            : Translator::get($template, $replace);
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @return list<array{0: string|Rule|Closure, 1: list<string>}> */
    private function parse(string|array $spec): array
    {
        $out = [];
        foreach (is_array($spec) ? $spec : explode('|', $spec) as $rule) {
            if ($rule instanceof Rule || $rule instanceof Closure) {
                $out[] = [$rule, []];
                continue;
            }
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            [$name, $paramString] = array_pad(explode(':', $rule, 2), 2, null);
            $params = $paramString === null ? [] : (in_array($name, ['regex', 'not_regex', 'date_format'], true) ? [$paramString] : str_getcsv($paramString, ',', '"', ''));
            $out[] = [$name, array_map('strval', $params)];
        }

        return $out;
    }

    /** "items.*.name" → ["items.0.name", "items.1.name"]; plain keys unchanged. @return list<string> */
    private function expand(string $pattern): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }
        $paths = [''];
        foreach (explode('.', $pattern) as $segment) {
            $next = [];
            foreach ($paths as $prefix) {
                if ($segment === '*') {
                    $node = $prefix === '' ? $this->data : Arr::get($this->data, $prefix);
                    if (is_array($node)) {
                        foreach (array_keys($node) as $key) {
                            $next[] = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                        }
                    }
                } else {
                    $next[] = $prefix === '' ? $segment : "{$prefix}.{$segment}";
                }
            }
            $paths = $next;
        }

        return $paths;
    }

    private function present(string $path): bool
    {
        $node = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return true;
    }

    /** Resolve a field reference relative to the current path: "items.0.qty" + "price" → "items.0.price" when that exists. */
    private function sibling(string $path, string $field): string
    {
        if ($field === '' || !str_contains($path, '.') || str_contains($field, '.')) {
            return $field;
        }
        $candidate = substr($path, 0, (int) strrpos($path, '.')) . '.' . $field;

        return $this->present($candidate) ? $candidate : $field;
    }

    private function attributeName(string $path, string $pattern): string
    {
        return $this->aliases[$path] ?? $this->aliases[$pattern] ?? str_replace('_', ' ', $path);
    }

    /** @return 'numeric'|'file'|'array'|'string'|null */
    private function sizeKind(mixed $value, bool $numeric): ?string
    {
        return match (true) {
            self::file($value) !== null => 'file',
            is_array($value) => 'array',
            $numeric && is_numeric($value), is_int($value), is_float($value) => 'numeric',
            is_string($value) => 'string',
            default => null,
        };
    }

    private function measure(mixed $value, string $kind): float
    {
        return match ($kind) {
            'numeric' => (float) $value,
            'array' => count($value),
            'file' => self::file($value)['size'] / 1024,
            default => mb_strlen((string) $value),
        };
    }

    private function operand(string $path, string $param, ?string $kind): ?float
    {
        if (is_numeric($param)) {
            return (float) $param;
        }
        $other = Arr::get($this->data, $this->sibling($path, $param));
        $otherKind = $this->sizeKind($other, is_numeric($other));

        return $otherKind === null || ($kind !== null && $otherKind !== $kind && !($kind === 'string' && $otherKind === 'numeric')) ? null : $this->measure($other, $otherKind);
    }

    private function compare(mixed $value, string $op, ?float $operand, ?string $kind): bool
    {
        if ($operand === null || $kind === null) {
            return false;
        }
        $v = $kind === 'string' && is_numeric($value) ? (float) $value : $this->measure($value, $kind);

        return match ($op) {
            'gt' => $v > $operand,
            'gte' => $v >= $operand,
            'lt' => $v < $operand,
            default => $v <= $operand,
        };
    }

    private function compareDates(mixed $value, string $op, string $path, string $param): bool
    {
        $date = self::date($value);
        $otherValue = $this->present($this->sibling($path, $param)) ? Arr::get($this->data, $this->sibling($path, $param)) : $param;
        $other = self::date($otherValue);
        if ($date === null || $other === null) {
            return false;
        }
        $a = $date->getTimestamp();
        $b = $other->getTimestamp();

        return match ($op) {
            'after' => $a > $b,
            'after_or_equal' => $a >= $b,
            'before' => $a < $b,
            default => $a <= $b,
        };
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if ($value instanceof Date) {
            return $value->toDateTimeImmutable();
        }
        if (!is_string($value) || trim($value) === '' || is_numeric($value)) {
            return null;
        }
        try {
            $d = Date::parse($value)->toDateTimeImmutable();
        } catch (\Throwable) {
            return null;
        }
        // Reject impossible dates PHP would roll over ("2026-02-30").
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $d;
    }

    private function isDistinct(string $path, string $pattern, mixed $value): bool
    {
        if (!str_contains($pattern, '*')) {
            return true;
        }
        $seen = 0;
        foreach ($this->expand($pattern) as $p) {
            if ($this->present($p) && self::str(Arr::get($this->data, $p)) === self::str($value)) {
                $seen++;
            }
        }

        return $seen <= 1;
    }

    /** @param list<string> $fields */
    private function allEmpty(string $path, array $fields): bool
    {
        foreach ($fields as $f) {
            if (!self::isEmpty(Arr::get($this->data, $this->sibling($path, $f)))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $fields */
    private function anyEmpty(string $path, array $fields): bool
    {
        foreach ($fields as $f) {
            if (self::isEmpty(Arr::get($this->data, $this->sibling($path, $f)))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $params */
    private function pattern(array $params): string
    {
        $pattern = $params[0] ?? '';
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Invalid regex rule \"{$pattern}\".");
        }

        return $pattern;
    }

    /**
     * An uploaded file ($_FILES shape) whose upload succeeded.
     *
     * @return array{name: string, tmp_name: string, size: int}|null
     */
    private static function file(mixed $value): ?array
    {
        if (!is_array($value) || !isset($value['tmp_name'], $value['name']) || !is_string($value['tmp_name']) || !is_string($value['name'])) {
            return null;
        }
        if ((int) ($value['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || $value['tmp_name'] === '' || !is_file($value['tmp_name'])) {
            return null;
        }

        return ['name' => $value['name'], 'tmp_name' => $value['tmp_name'], 'size' => (int) filesize($value['tmp_name'])];
    }

    /**
     * Both the client's extension and the sniffed content must be in the list.
     *
     * @param array{name: string, tmp_name: string, size: int} $f
     * @param list<string> $extensions
     */
    private function mimes(array $f, array $extensions): bool
    {
        $extensions = array_map(static fn($e) => strtolower(ltrim($e, '.')), $extensions);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (Mime::isDangerous($f['name']) || !in_array($ext, $extensions, true)) {
            return false;
        }
        $detected = Mime::detectBytes((string) file_get_contents($f['tmp_name'], false, null, 0, 4096), $f['name']);
        $allowed = array_map([Mime::class, 'fromExtension'], $extensions);
        // Plain-text formats (txt, csv, md) can't be told apart by content.
        $text = in_array($detected, ['text/plain', 'text/csv', 'text/markdown'], true) && array_intersect($allowed, ['text/plain', 'text/csv', 'text/markdown']) !== [];

        return in_array($detected, $allowed, true) || $text;
    }

    /**
     * @param array{name: string, tmp_name: string, size: int} $f
     * @param list<string> $types e.g. image/*, application/pdf
     */
    private function mimetypes(array $f, array $types): bool
    {
        if (Mime::isDangerous($f['name'])) {
            return false;
        }
        $detected = Mime::detectBytes((string) file_get_contents($f['tmp_name'], false, null, 0, 4096), $f['name']);
        foreach ($types as $type) {
            if ($type === $detected || (str_ends_with($type, '/*') && str_starts_with($detected, substr($type, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * dimensions:min_width=100,max_height=2000,ratio=16/9 (read from the header, no decoding).
     *
     * @param array{name: string, tmp_name: string, size: int} $f
     * @param list<string> $params
     */
    private function dimensions(array $f, array $params): bool
    {
        $info = @getimagesize($f['tmp_name']);
        if ($info === false) {
            return false;
        }
        [$w, $h] = $info;
        foreach ($params as $p) {
            [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
            $ok = match ($k) {
                'min_width' => $w >= (int) $v, 'max_width' => $w <= (int) $v,
                'min_height' => $h >= (int) $v, 'max_height' => $h <= (int) $v,
                'width' => $w === (int) $v, 'height' => $h === (int) $v,
                'ratio' => $h > 0 && abs($w / $h - (str_contains($v, '/') ? (float) explode('/', $v)[0] / max(1.0, (float) explode('/', $v)[1]) : (float) $v)) < 0.01,
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** unique:table,column[,except_id[,id_column]] */
    private function ruleUnique(mixed $value, array $params): bool
    {
        if (!DatabaseManager::hasConnection() || !is_scalar($value)) {
            return true;
        }
        [$table, $column] = [$params[0] ?? '', $params[1] ?? ''];
        if (!self::identifier($table) || !self::identifier($column)) {
            throw new InvalidArgumentException('unique: table and column are required.');
        }
        if (self::notAnId($column, $value)) {
            return true;        // "abc" can't collide with an integer id
        }
        $query = QueryBuilder::table($table)->where($column, $value);
        $except = $params[2] ?? '';
        if ($except !== '' && $except !== 'NULL') {
            $idColumn = $params[3] ?? 'id';
            if (!self::identifier($idColumn)) {
                throw new InvalidArgumentException('unique: invalid id column.');
            }
            $query = $query->where($idColumn, '!=', $except);
        }

        try {
            return !$query->exists();
        } catch (\PDOException $e) {
            if (self::typeMismatch($e)) {
                return true;
            }
            throw $e;
        }
    }

    /** exists:table,column */
    private function ruleExists(mixed $value, array $params): bool
    {
        if (!DatabaseManager::hasConnection()) {
            return true;
        }
        [$table, $column] = [$params[0] ?? '', $params[1] ?? 'id'];
        if (!self::identifier($table) || !self::identifier($column)) {
            throw new InvalidArgumentException('exists: table and column are required.');
        }
        $values = is_array($value) ? array_values(array_unique(array_map(self::str(...), $value))) : [self::str($value)];
        if ($values === [] || count(array_filter($values, 'is_string')) !== count($values)) {
            return false;
        }

        foreach ($values as $v) {
            if (self::notAnId($column, $v)) {
                return false;   // PostgreSQL refuses "abc" for an integer column instead of finding nothing
            }
        }
        try {
            return QueryBuilder::table($table)->whereIn($column, $values)->count() === count($values);
        } catch (\PDOException $e) {
            if (self::typeMismatch($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** id and *_id columns hold integers: a value that isn't one can't match. */
    private static function notAnId(string $column, mixed $value): bool
    {
        return ($column === 'id' || str_ends_with($column, '_id')) && !is_int($value) && !(is_string($value) && preg_match('/^-?\d{1,19}$/', $value) === 1);
    }

    /** PostgreSQL: a value that doesn't fit the column's type (22P02, 22003, 22007, 22008). */
    private static function typeMismatch(\PDOException $e): bool
    {
        return in_array((string) $e->getCode(), ['22P02', '22003', '22007', '22008'], true);
    }

    private static function identifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) === 1;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && ($value === [] || (isset($value['tmp_name']) && (int) ($value['error'] ?? 0) === UPLOAD_ERR_NO_FILE)));
    }

    private static function str(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    private static function isBuiltIn(string $name): bool
    {
        static $builtIn = null;
        $builtIn ??= array_flip([...self::IMPLICIT, 'string', 'email', 'url', 'numeric', 'integer', 'boolean', 'array', 'list', 'alpha', 'alpha_num', 'alpha_dash', 'slug',
            'min', 'max', 'size', 'between', 'gt', 'gte', 'lt', 'lte', 'digits', 'digits_between', 'in', 'not_in', 'regex', 'not_regex', 'starts_with', 'ends_with',
            'lowercase', 'uppercase', 'confirmed', 'same', 'different', 'distinct', 'unique', 'exists', 'date', 'date_format', 'after', 'after_or_equal', 'before',
            'before_or_equal', 'timezone', 'uuid', 'ulid', 'json', 'ip', 'ipv4', 'ipv6', 'hex_color', 'color', 'file', 'image', 'mimes', 'mimetypes', 'dimensions',
            'nullable', 'sometimes', 'bail']);

        return isset($builtIn[$name]);
    }
}
