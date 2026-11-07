<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

/**
 * "Show this field when…" rules, shared by content fields and forms:
 *
 *   ['match' => 'all', 'rules' => [['field' => 'kind', 'op' => 'is', 'value' => 'other']]]
 *
 * A rule may only look at fields above it, so one pass from the top
 * decides everything and rules can't loop. A field that is hidden counts
 * as empty for the rules below it. Hidden fields are never required.
 * The browser runs the same rules (visibleFields() in @kestello/sdk).
 *
 * @api
 */
final class Conditions
{
    public const array OPS = ['is', 'is_not', 'filled', 'empty', 'contains'];
    private const int MAX_RULES = 10;

    /**
     * Clean a field's conditions. $earlier: names of the fields above it.
     *
     * @param list<string> $earlier
     * @return array{0: ?array{match: string, rules: list<array{field: string, op: string, value: string}>}, 1: list<string>}
     */
    public static function normalize(mixed $in, array $earlier): array
    {
        if (!is_array($in) || !is_array($in['rules'] ?? null) || $in['rules'] === []) {
            return [null, []];
        }
        $errors = [];
        $rules = [];
        foreach (array_slice(array_values($in['rules']), 0, self::MAX_RULES) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? 'is');
            if (!in_array($field, $earlier, true)) {
                $errors[] = $field === '' ? 'Choose the field a rule looks at.' : "A rule can only look at fields above this one ({$field} isn't).";
                continue;
            }
            if (!in_array($op, self::OPS, true)) {
                $errors[] = 'Unknown comparison.';
                continue;
            }
            $value = in_array($op, ['filled', 'empty'], true) ? '' : mb_substr(trim((string) (is_scalar($rule['value'] ?? null) ? $rule['value'] : '')), 0, 200);
            $rules[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }
        if ($rules === []) {
            return [null, $errors];
        }

        return [['match' => ($in['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules], $errors];
    }

    /**
     * Which fields show for $data, in order.
     *
     * @param list<array{name: string, conditions: ?array}> $fields
     * @return array<string, bool> name => visible
     */
    public static function visible(array $fields, array $data): array
    {
        $visible = [];
        foreach ($fields as $f) {
            $c = $f['conditions'] ?? null;
            if (!is_array($c) || ($c['rules'] ?? []) === []) {
                $visible[$f['name']] = true;
                continue;
            }
            $results = array_map(static function (array $r) use ($data, $visible): bool {
                $value = ($visible[$r['field']] ?? false) ? ($data[$r['field']] ?? null) : null;
                return self::test($value, (string) $r['op'], (string) ($r['value'] ?? ''));
            }, $c['rules']);
            $visible[$f['name']] = ($c['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
        }

        return $visible;
    }

    /** @param list<Field> $fields */
    public static function visibleFields(array $fields, array $data): array
    {
        return self::visible(array_map(static fn(Field $f) => ['name' => $f->name, 'conditions' => $f->getOption('conditions')], $fields), $data);
    }

    private static function test(mixed $value, string $op, string $expected): bool
    {
        $empty = $value === null || $value === '' || $value === [] || $value === false;
        $strings = is_array($value) ? array_map(self::text(...), array_filter($value, 'is_scalar')) : [self::text($value)];

        return match ($op) {
            'filled' => !$empty,
            'empty' => $empty,
            'is' => !$empty && in_array($expected, $strings, true),
            'is_not' => $empty || !in_array($expected, $strings, true),
            'contains' => !$empty && $expected !== '' && array_filter($strings, static fn(string $s) => mb_stripos($s, $expected) !== false) !== [],
            default => false,
        };
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === true => '1',
            $value === false, $value === null => '',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
