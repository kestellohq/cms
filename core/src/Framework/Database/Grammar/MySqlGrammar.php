<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

/** MySQL 8 / MariaDB 10.6+. */
final class MySqlGrammar extends QueryGrammar
{
    protected string $quote = '`';

    public function driver(): string
    {
        return 'mysql';
    }

    protected function compileRandom(): string
    {
        return 'RAND()';
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        // MySQL needs a LIMIT before OFFSET.
        if ($limit === null && $offset !== null && $offset > 0) {
            return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
        }

        return parent::compileLimitOffset($limit, $offset);
    }

    protected function compileLock(string $lock): string
    {
        return $lock === 'update' ? ' FOR UPDATE' : ' LOCK IN SHARE MODE';
    }

    public function compileUpsert(string $table, array $rows, array $uniqueBy, array $update): array
    {
        [$sql, $bindings] = $this->compileInsert($table, $rows);

        if ($update === []) {
            // Keep the existing row: a no-op assignment on the first unique column.
            $first = $this->wrap($uniqueBy[0]);
            return [$sql . " ON DUPLICATE KEY UPDATE {$first} = {$first}", $bindings];
        }

        $sets = array_map(fn(string $c): string => $this->wrap($c) . ' = VALUES(' . $this->wrap($c) . ')', $update);

        return [$sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets), $bindings];
    }

    public function compileTruncate(string $table): array
    {
        return ['TRUNCATE TABLE ' . $this->wrapTable($table)];
    }
}
