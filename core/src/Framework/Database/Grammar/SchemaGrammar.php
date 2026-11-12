<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\ColumnDefinition;
use Kestello\Framework\Database\ForeignKey;
use LogicException;

/**
 * Compiles Blueprints into DDL statements for one dialect.
 */
abstract class SchemaGrammar
{
    public function __construct(protected readonly QueryGrammar $query) {}

    /** Native type for a column. */
    abstract protected function type(ColumnDefinition $column): string;

    /** @return array{0: string, 1: list<mixed>} SQL returning one row with column "n" > 0 when the table exists */
    abstract public function compileHasTable(string $table): array;

    /** @return array{0: string, 1: list<mixed>} SQL returning rows with a "name" column */
    abstract public function compileColumns(string $table): array;

    /** @return array{0: string, 1: list<mixed>} SQL returning a "name" row per table in the current database or schema */
    abstract public function compileTables(): array;

    /** @return array{0: string, 1: list<mixed>} SQL returning a "name" row per index of a table, its primary key included */
    abstract public function compileIndexes(string $table): array;

    // ── Create ──────────────────────────────────────────────────────

    /** @return list<string> */
    public function compileCreate(Blueprint $blueprint, array $options = []): array
    {
        $lines = [];
        foreach ($blueprint->columns() as $column) {
            $lines[] = $this->columnSql($column, creating: true);
        }

        $primary = $blueprint->primaryKey();
        if ($primary === []) {
            foreach ($blueprint->columns() as $column) {
                if ($column->primary && !$this->inlinePrimary($column)) {
                    $primary[] = $column->name;
                }
            }
        }
        if ($primary !== []) {
            $lines[] = 'PRIMARY KEY (' . $this->columnList($primary) . ')';
        }

        foreach ($blueprint->foreignKeys() as $fk) {
            $lines[] = $this->foreignKeySql($fk);
        }

        $statements = ['CREATE TABLE ' . $this->wrap($blueprint->table) . " (\n  " . implode(",\n  ", $lines) . "\n)" . $this->tableSuffix($options)];

        foreach ($blueprint->indexes() as $index) {
            $statements[] = $this->indexSql($blueprint->table, $index);
        }

        return $statements;
    }

    // ── Alter ───────────────────────────────────────────────────────

    /** @return list<string> */
    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->wrap($blueprint->table);
        $statements = [];

        foreach ($blueprint->columns() as $column) {
            if ($column->primary || $column->autoIncrement) {
                throw new LogicException("Cannot add primary/auto-increment column [{$column->name}] to an existing table.");
            }
            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->columnSql($column, creating: false);
        }

        foreach ($blueprint->commands() as $command) {
            $statements[] = match ($command['type']) {
                'dropColumn' => "ALTER TABLE {$table} DROP COLUMN " . $this->wrap($command['column']),
                'renameColumn' => "ALTER TABLE {$table} RENAME COLUMN " . $this->wrap($command['from']) . ' TO ' . $this->wrap($command['to']),
                'dropIndex' => $this->dropIndexSql($blueprint->table, $command['name']),
                'dropForeign' => $this->dropForeignSql($blueprint->table, $command['name']),
            };
        }

        foreach ($blueprint->indexes() as $index) {
            $statements[] = $this->indexSql($blueprint->table, $index);
        }

        foreach ($blueprint->foreignKeys() as $fk) {
            $statements[] = $this->addForeignSql($fk);
        }

        return $statements;
    }

    // ── Other statements ────────────────────────────────────────────

    public function compileDrop(string $table, bool $ifExists = false): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($from) . ' RENAME TO ' . $this->wrap($to);
    }

    /** Statements to run before/after dropping many tables (foreign key checks). */
    public function compileDisableForeignKeys(): ?string
    {
        return null;
    }

    public function compileEnableForeignKeys(): ?string
    {
        return null;
    }

    // ── Pieces ──────────────────────────────────────────────────────

    protected function columnSql(ColumnDefinition $column, bool $creating): string
    {
        $sql = $this->wrap($column->name) . ' ' . $this->type($column) . $this->modifiers($column);

        if ($creating && $this->inlinePrimary($column)) {
            return $sql;
        }

        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($column->default, $column);
        }

        if ($column->unique && $creating) {
            $sql .= ' UNIQUE';
        }

        if ($column->type === 'enum') {
            $sql .= ' CHECK (' . $this->wrap($column->name) . ' IN (' . implode(', ', array_map(fn($v) => $this->literal($v), $column->options['values'])) . '))';
        }

        return $sql;
    }

    /** Extra type modifiers (UNSIGNED, AUTO_INCREMENT…). */
    protected function modifiers(ColumnDefinition $column): string
    {
        return '';
    }

    /** Whether the column's definition already includes PRIMARY KEY. */
    protected function inlinePrimary(ColumnDefinition $column): bool
    {
        return false;
    }

    protected function tableSuffix(array $options): string
    {
        return '';
    }

    /** @param array{type: string, columns: list<string>, name: string} $index */
    protected function indexSql(string $table, array $index): string
    {
        return 'CREATE ' . ($index['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX ' . $this->wrap($index['name'])
            . ' ON ' . $this->wrap($table) . ' (' . $this->columnList($index['columns']) . ')';
    }

    protected function dropIndexSql(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name);
    }

    protected function foreignKeySql(ForeignKey $fk): string
    {
        if ($fk->referencedTable === '') {
            throw new LogicException("Foreign key on [{$fk->table}.{$fk->column}] has no referenced table (call ->on()).");
        }

        return 'CONSTRAINT ' . $this->wrap($fk->name()) . ' FOREIGN KEY (' . $this->wrap($fk->column) . ') REFERENCES '
            . $this->wrap($fk->referencedTable) . ' (' . $this->wrap($fk->referencedColumn) . ')'
            . ' ON DELETE ' . $fk->onDelete . ' ON UPDATE ' . $fk->onUpdate;
    }

    protected function addForeignSql(ForeignKey $fk): string
    {
        return 'ALTER TABLE ' . $this->wrap($fk->table) . ' ADD ' . $this->foreignKeySql($fk);
    }

    protected function dropForeignSql(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP CONSTRAINT ' . $this->wrap($name);
    }

    /** A default value as a SQL literal. */
    protected function literal(mixed $value, ?ColumnDefinition $column = null): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    protected function wrap(string $name): string
    {
        return $this->query->quoteName($name);
    }

    /** @param list<string> $columns */
    protected function columnList(array $columns): string
    {
        return implode(', ', array_map(fn(string $c) => $this->wrap($c), $columns));
    }
}
