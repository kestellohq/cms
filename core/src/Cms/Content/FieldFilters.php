<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use DateTimeImmutable;
use DateTimeZone;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Translator;
use Throwable;

/**
 * Filters on indexed() fields, read from entry_index (so no JSON is scanned).
 *
 *   filter[level]=pro                         equal
 *   filter[level][]=intro&filter[level][]=pro one of (or filter[level][in]=intro,pro)
 *   filter[price][gte]=10&filter[price][lt]=50
 *   filter[starts_at][gte]=2027-03-01         dates: a bare date means the whole day
 *   filter[venue][exists]=false               has no value
 *
 * Operators: eq, in, gt, gte, lt, lte, exists. Ranges work on number and
 * date fields. For fields holding several values (a list of related
 * entries, say), a condition matches when one of the values does, and the
 * range bounds apply to that same value.
 *
 *   $conditions = FieldFilters::parse($filters, $type, $cms->content->fields);   // throws FieldFilterException
 *   FieldFilters::apply($query, $conditions);                                    // a query on entries
 *
 * @api
 */
final class FieldFilters
{
    public const array OPERATORS = ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'exists'];

    public const int MAX_FIELDS = 10;

    public const int MAX_IN = 50;

    /**
     * @param array<string, mixed> $filters field name => value or [operator => value]
     * @return list<array{field: string, column: string, ops: list<array{0: string, 1: mixed}>}>
     */
    public static function parse(array $filters, ContentType $type, FieldTypes $types): array
    {
        if (count($filters) > self::MAX_FIELDS) {
            throw new FieldFilterException(['_' => ['Filter on at most ' . self::MAX_FIELDS . ' fields.']]);
        }
        $out = [];
        $errors = [];
        foreach ($filters as $name => $spec) {
            $name = (string) $name;
            $field = $type->getField($name);
            if ($field === null || !$field->isIndexed()) {
                $errors[$name][] = Translator::get('Not an indexed field.');
                continue;
            }
            $kind = $types->get($field->type)->indexKind($field);
            $ops = [];
            foreach (self::operators($spec) as [$op, $value]) {
                if (!in_array($op, self::OPERATORS, true)) {
                    $errors[$name][] = Translator::get('Unknown operator "{op}". Use one of: {list}.', ['op' => $op, 'list' => implode(', ', self::OPERATORS)]);
                    continue;
                }
                try {
                    array_push($ops, ...self::normalize($op, $value, $kind));
                } catch (FieldFilterException $e) {
                    $errors[$name] = [...($errors[$name] ?? []), ...$e->errors['_']];
                }
            }
            if ($ops !== []) {
                $out[] = ['field' => $field->name, 'column' => 'value_' . $kind, 'ops' => $ops];
            }
        }
        if ($errors !== []) {
            throw new FieldFilterException($errors);
        }

        return $out;
    }

    /** @param list<array{field: string, column: string, ops: list<array{0: string, 1: mixed}>}> $conditions */
    public static function apply(QueryBuilder|ModelQuery $query, array $conditions, string $entries = 'entries'): void
    {
        foreach ($conditions as $c) {
            $exists = null;
            $where = [];
            foreach ($c['ops'] as [$op, $value]) {
                if ($op === 'exists') {
                    $exists = $value;
                } else {
                    $where[] = [$op, $value];
                }
            }
            $row = static function (QueryBuilder $q) use ($c, $where, $entries): void {
                $q->from('entry_index')->whereColumn('entry_index.entry_id', '=', "{$entries}.id")->where('entry_index.field', $c['field']);
                foreach ($where as [$op, $value]) {
                    match ($op) {
                        'eq' => $q->where("entry_index.{$c['column']}", $value),
                        'in' => $q->whereIn("entry_index.{$c['column']}", $value),
                        'gt' => $q->where("entry_index.{$c['column']}", '>', $value),
                        'gte' => $q->where("entry_index.{$c['column']}", '>=', $value),
                        'lt' => $q->where("entry_index.{$c['column']}", '<', $value),
                        'lte' => $q->where("entry_index.{$c['column']}", '<=', $value),
                    };
                }
            };
            if ($exists === false) {
                $query->whereExists($row, true);
            } elseif ($where !== [] || $exists === true) {
                $query->whereExists($row);
            }
        }
    }

    /**
     * filter[x]=v, filter[x][]=a, filter[x][op]=v as [operator, value] pairs.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function operators(mixed $spec): array
    {
        if (!is_array($spec)) {
            return [['eq', $spec]];
        }
        if (array_is_list($spec)) {
            return [['in', $spec]];
        }
        $out = [];
        foreach ($spec as $op => $value) {
            $out[] = [strtolower((string) $op), $value];
        }

        return $out;
    }

    /**
     * The operator with its value in the form entry_index stores. A bare
     * date becomes a range over the day for eq, and the right edge of the
     * day for the other comparisons.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function normalize(string $op, mixed $value, string $kind): array
    {
        if ($op === 'exists') {
            return match (is_string($value) ? strtolower($value) : $value) {
                true, 'true', '1', 1 => [['exists', true]],
                false, 'false', '0', 0 => [['exists', false]],
                default => throw new FieldFilterException(['_' => ['exists takes true or false.']]),
            };
        }
        if ($op === 'in') {
            $values = is_array($value) ? array_values($value) : explode(',', (string) $value);
            $values = array_values(array_filter(array_map(static fn($v) => is_string($v) ? trim($v) : $v, $values), static fn($v) => $v !== '' && $v !== null));
            if ($values === [] || count($values) > self::MAX_IN) {
                throw new FieldFilterException(['_' => [$values === [] ? 'in needs at least one value.' : 'in takes at most ' . self::MAX_IN . ' values.']]);
            }
            if ($kind === 'date') {
                throw new FieldFilterException(['_' => ['Use gte and lte for dates.']]);
            }

            return [['in', array_map(static fn($v) => self::scalar($v, $kind), $values)]];
        }
        if (is_array($value)) {
            throw new FieldFilterException(['_' => ["{$op} takes one value."]]);
        }
        if ($kind === 'string' && $op !== 'eq') {
            throw new FieldFilterException(['_' => ['Ranges work on number and date fields.']]);
        }
        if ($kind !== 'date') {
            return [[$op, self::scalar($value, $kind)]];
        }

        [$start, $end] = self::day((string) $value);
        if ($end === null) {
            return [[$op, $start]];
        }

        return match ($op) {
            'eq' => [['gte', $start], ['lte', $end]],
            'gt', 'lte' => [[$op, $end]],
            default => [[$op, $start]],      // gte, lt
        };
    }

    private static function scalar(mixed $value, string $kind): string|int|float
    {
        if ($kind === 'number') {
            if (is_bool($value) || in_array(is_string($value) ? strtolower($value) : null, ['true', 'false'], true)) {
                return ($value === true || strtolower((string) $value) === 'true') ? 1 : 0;     // boolean fields store 1 and 0
            }
            if (!is_numeric($value)) {
                throw new FieldFilterException(['_' => ['Must be a number.']]);
            }

            return $value + 0;
        }
        $value = (string) $value;
        if (mb_strlen($value) > 191) {
            throw new FieldFilterException(['_' => ['Must be at most 191 characters.']]);
        }

        return $value;
    }

    /** @return array{0: string, 1: ?string} the moment, or a bare date's first and last second (UTC) */
    private static function day(string $value): array
    {
        $value = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $d = new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone('UTC'));
                if ($d->format('Y-m-d') !== $value) {
                    throw new \RuntimeException('not a real day');
                }

                return [$d->format('Y-m-d H:i:s'), $d->format('Y-m-d') . ' 23:59:59'];
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
                throw new \RuntimeException('not ISO 8601');
            }

            return [(new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), null];
        } catch (Throwable) {
            throw new FieldFilterException(['_' => ['Must be a date (2027-03-01) or a date and time (2027-03-01T18:00:00Z).']]);
        }
    }
}
