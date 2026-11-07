<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

/** SQLite 3.35+. */
final class SqliteGrammar extends QueryGrammar
{
    public function driver(): string
    {
        return 'sqlite';
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset !== null && $offset > 0) {
            return ' LIMIT -1 OFFSET ' . $offset;
        }

        return parent::compileLimitOffset($limit, $offset);
    }

    /** SQLite has row-level locking only per database; transactions serialize writes. */
    protected function compileLock(string $lock): string
    {
        return '';
    }

    /** SQLite LIKE has no default escape character. */
    protected function likeEscape(): string
    {
        return " ESCAPE '\\'";
    }

    public function compileTruncate(string $table): array
    {
        return [
            'DELETE FROM ' . $this->wrapTable($table),
            // Reset AUTOINCREMENT counters (the table only exists once one was used).
            'DELETE FROM sqlite_sequence WHERE name = ' . "'" . str_replace("'", "''", $table) . "'",
        ];
    }
}
