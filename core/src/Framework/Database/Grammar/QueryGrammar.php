<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

use InvalidArgumentException;
use Kestello\Framework\Database\Expression;

/**
 * Compiles query builder state into SQL for one dialect.
 *
 * Every method returns SQL with "?" placeholders; values are never
 * interpolated. Identifiers are validated and quoted with wrap().
 */
abstract class QueryGrammar
{
    /** Identifier quote character. */
    protected string $quote = '"';

    abstract public function driver(): string;

    // ── Identifiers ─────────────────────────────────────────────────

    /**
     * Quote a plain identifier ("key", "users.id", "users.*", "users.id as uid").
     * Expressions (COUNT(*), "*") pass through unchanged; never pass user
     * input as a column name.
     */
    public function wrap(string|Expression $identifier): string
    {
        if ($identifier instanceof Expression) {
            return $identifier->sql;
        }

        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $identifier, $m) === 1
            && self::isPlain($m[1])) {
            return $this->wrap($m[1]) . ' AS ' . $this->quoteName($m[2]);
        }

        if ($identifier === '*') {
            return '*';
        }
        if (!self::isPlain($identifier)) {
            // never pass a string through as SQL: a column name built from input would be injection
            throw new \InvalidArgumentException('Not a column or table name: "' . mb_substr($identifier, 0, 80) . '". Use name, table.name, name AS alias, or new Expression(…) for raw SQL.');
        }

        return implode('.', array_map(
            fn(string $part): string => $part === '*' ? '*' : $this->quoteName($part),
            explode('.', $identifier),
        ));
    }

    public function wrapTable(string $table): string
    {
        return $this->wrap($table);
    }

    public function quoteName(string $name): string
    {
        return $this->quote . str_replace($this->quote, $this->quote . $this->quote, $name) . $this->quote;
    }

    private static function isPlain(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.([A-Za-z_][A-Za-z0-9_]*|\*))?$/', $identifier) === 1;
    }

    // ── SELECT ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $q query state (see QueryBuilder::state())
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileSelect(array $q): array
    {
        $bindings = [];
        $columns = array_map(fn($c) => $this->wrap($c), $q['columns']);

        $sql = 'SELECT ' . ($q['distinct'] ? 'DISTINCT ' : '') . implode(', ', $columns)
            . ' FROM ' . $this->wrapTable($q['table']);

        foreach ($q['joins'] as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $this->wrapTable($join['table']);
            if ($join['wheres'] !== []) {
                [$on, $onBindings] = $this->compileConditions($join['wheres']);
                $sql .= ' ON ' . $on;
                array_push($bindings, ...$onBindings);
            }
        }

        [$where, $whereBindings] = $this->compileWheres($q['wheres']);
        $sql .= $where;
        array_push($bindings, ...$whereBindings);

        if ($q['groups'] !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($g) => $this->wrap($g), $q['groups']));
        }

        if ($q['havings'] !== []) {
            [$having, $havingBindings] = $this->compileConditions($q['havings']);
            $sql .= ' HAVING ' . $having;
            array_push($bindings, ...$havingBindings);
        }

        if ($q['orders'] !== []) {
            $orders = [];
            foreach ($q['orders'] as $order) {
                $orders[] = $order['random'] ?? false
                    ? $this->compileRandom()
                    : $this->wrap($order['column']) . ' ' . $order['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        $sql .= $this->compileLimitOffset($q['limit'], $q['offset']);

        if ($q['lock'] !== null) {
            $sql .= $this->compileLock($q['lock']);
        }

        return [$sql, $bindings];
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    protected function compileRandom(): string
    {
        return 'RANDOM()';
    }

    protected function compileLock(string $lock): string
    {
        return $lock === 'update' ? ' FOR UPDATE' : ' FOR SHARE';
    }

    // ── WHERE ───────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $wheres
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileWheres(array $wheres): array
    {
        if ($wheres === []) {
            return ['', []];
        }

        [$sql, $bindings] = $this->compileConditions($wheres);

        return [' WHERE ' . $sql, $bindings];
    }

    /**
     * @param list<array<string, mixed>> $wheres
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileConditions(array $wheres): array
    {
        $sql = '';
        $bindings = [];

        foreach ($wheres as $i => $where) {
            [$clause, $clauseBindings] = $this->compileCondition($where);
            $sql .= ($i === 0 ? '' : ' ' . $where['boolean'] . ' ') . $clause;
            array_push($bindings, ...$clauseBindings);
        }

        return [$sql, $bindings];
    }

    /**
     * @param array<string, mixed> $w
     * @return array{0: string, 1: list<mixed>}
     */
    protected function compileCondition(array $w): array
    {
        return match ($w['type']) {
            'basic' => $this->compileBasic($w),
            'column' => [$this->wrap($w['first']) . ' ' . $w['operator'] . ' ' . $this->wrap($w['second']), []],
            'in' => $w['values'] === []
                ? [$w['not'] ? '1 = 1' : '1 = 0', []]
                : [$this->wrap($w['column']) . ($w['not'] ? ' NOT IN (' : ' IN (') . $this->placeholders($w['values']) . ')', array_values($w['values'])],
            'null' => [$this->wrap($w['column']) . ($w['not'] ? ' IS NOT NULL' : ' IS NULL'), []],
            'between' => [$this->wrap($w['column']) . ($w['not'] ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?'), [$w['from'], $w['to']]],
            'like' => $this->compileLike($w),
            'nested' => $this->wrapNested($this->compileConditions($w['wheres'])),
            'exists' => (function () use ($w): array {
                [$sub, $b] = $this->compileSelect($w['query']);
                return [($w['not'] ? 'NOT EXISTS (' : 'EXISTS (') . $sub . ')', $b];
            })(),
            'raw' => [$w['sql'], $w['bindings']],
            default => throw new InvalidArgumentException("Unknown where type [{$w['type']}]."),
        };
    }

    /** @param array<string, mixed> $w */
    protected function compileBasic(array $w): array
    {
        $sql = $this->wrap($w['column']) . ' ' . $w['operator'] . ' ?';

        if (in_array($w['operator'], ['LIKE', 'NOT LIKE'], true)) {
            $sql .= $this->likeEscape();
        }

        return [$sql, [$w['value']]];
    }

    /**
     * Case-insensitive LIKE (whereLike). The value is used as a pattern;
     * escape literals with QueryBuilder::escapeLike().
     *
     * @param array<string, mixed> $w
     */
    protected function compileLike(array $w): array
    {
        $op = $w['not'] ? 'NOT LIKE' : 'LIKE';

        return ['LOWER(' . $this->wrap($w['column']) . ') ' . $op . ' LOWER(?)' . $this->likeEscape(), [$w['value']]];
    }

    /** ESCAPE clause so "\" escapes % and _ in every dialect. */
    protected function likeEscape(): string
    {
        return '';
    }

    /** @param array{0: string, 1: list<mixed>} $compiled */
    private function wrapNested(array $compiled): array
    {
        return ['(' . $compiled[0] . ')', $compiled[1]];
    }

    protected function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    // ── Writes ──────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows rows with identical keys
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileInsert(string $table, array $rows): array
    {
        $columns = array_keys($rows[0]);
        $row = '(' . $this->placeholders($columns) . ')';
        $bindings = [];

        foreach ($rows as $r) {
            foreach ($columns as $column) {
                $bindings[] = $r[$column] ?? null;
            }
        }

        return [
            'INSERT INTO ' . $this->wrapTable($table) . ' (' . implode(', ', array_map(fn($c) => $this->wrap($c), $columns)) . ') VALUES '
                . implode(', ', array_fill(0, count($rows), $row)),
            $bindings,
        ];
    }

    /** Suffix that makes INSERT return the new key, or '' when lastInsertId() is used. */
    public function compileReturning(string $key): string
    {
        return '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $uniqueBy
     * @param list<string> $update
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileUpsert(string $table, array $rows, array $uniqueBy, array $update): array
    {
        [$sql, $bindings] = $this->compileInsert($table, $rows);

        $sets = array_map(fn(string $c): string => $this->wrap($c) . ' = excluded.' . $this->wrap($c), $update);
        $sql .= ' ON CONFLICT (' . implode(', ', array_map(fn($c) => $this->wrap($c), $uniqueBy)) . ') '
            . ($sets === [] ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $sets));

        return [$sql, $bindings];
    }

    /**
     * @param array<string, mixed> $values column => value or Expression
     * @param array<string, mixed> $q
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileUpdate(array $q, array $values): array
    {
        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                $sets[] = $this->wrap($column) . ' = ' . $value->sql;
                array_push($bindings, ...$value->bindings);
            } else {
                $sets[] = $this->wrap($column) . ' = ?';
                $bindings[] = $value;
            }
        }

        [$where, $whereBindings] = $this->compileWheres($q['wheres']);

        return ['UPDATE ' . $this->wrapTable($q['table']) . ' SET ' . implode(', ', $sets) . $where, [...$bindings, ...$whereBindings]];
    }

    /**
     * @param array<string, mixed> $q
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileDelete(array $q): array
    {
        [$where, $bindings] = $this->compileWheres($q['wheres']);

        return ['DELETE FROM ' . $this->wrapTable($q['table']) . $where, $bindings];
    }

    /** @return list<string> */
    public function compileTruncate(string $table): array
    {
        return ['DELETE FROM ' . $this->wrapTable($table)];
    }
}
