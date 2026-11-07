<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

/** PostgreSQL 13+. */
final class PostgresGrammar extends QueryGrammar
{
    public function driver(): string
    {
        return 'pgsql';
    }

    protected function compileLike(array $w): array
    {
        return [$this->wrap($w['column']) . ($w['not'] ? ' NOT ILIKE ?' : ' ILIKE ?'), [$w['value']]];
    }

    public function compileReturning(string $key): string
    {
        return ' RETURNING ' . $this->wrap($key);
    }

    public function compileTruncate(string $table): array
    {
        return ['TRUNCATE TABLE ' . $this->wrapTable($table) . ' RESTART IDENTITY'];
    }
}
