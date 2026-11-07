<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

use Kestello\Framework\Database\ColumnDefinition;
use Kestello\Framework\Database\ForeignKey;
use LogicException;

final class SqliteSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $c): string
    {
        if ($c->autoIncrement) {
            return 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        return match ($c->type) {
            'string' => "VARCHAR({$c->options['length']})",
            'char' => "CHAR({$c->options['length']})",
            'text', 'mediumText', 'longText', 'json', 'uuid', 'enum' => 'TEXT',
            'tinyInteger', 'smallInteger', 'integer', 'bigInteger', 'boolean' => 'INTEGER',
            'decimal' => 'NUMERIC',
            'double' => 'REAL',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime' => 'DATETIME',
            'binary' => 'BLOB',
        };
    }

    protected function inlinePrimary(ColumnDefinition $column): bool
    {
        return $column->autoIncrement;
    }

    protected function addForeignSql(ForeignKey $fk): string
    {
        throw new LogicException("SQLite can't add foreign keys to existing tables ({$fk->table}.{$fk->column}); declare them when creating the table.");
    }

    protected function dropForeignSql(string $table, string $name): string
    {
        throw new LogicException("SQLite can't drop foreign keys from existing tables ({$name}).");
    }

    public function compileHasTable(string $table): array
    {
        return ["SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]];
    }

    public function compileColumns(string $table): array
    {
        return ['SELECT name FROM pragma_table_info(?) ORDER BY cid', [$table]];
    }

    public function compileTables(): array
    {
        // FTS5 shadow tables ("<virtual>_data" …) belong to their virtual table and are left out
        return ["SELECT t.name FROM sqlite_master t WHERE t.type = 'table' AND t.name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"
            . " AND NOT EXISTS (SELECT 1 FROM sqlite_master v WHERE v.type = 'table' AND v.sql LIKE 'CREATE VIRTUAL TABLE%' AND t.name LIKE v.name || '\\_%' ESCAPE '\\')"
            . ' ORDER BY t.name', []];
    }

    public function compileDisableForeignKeys(): ?string
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    public function compileEnableForeignKeys(): ?string
    {
        return 'PRAGMA foreign_keys = ON';
    }
}
