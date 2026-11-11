<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Grammar;

use Kestello\Framework\Database\ColumnDefinition;

final class MySqlSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $c): string
    {
        return match ($c->type) {
            'string' => "VARCHAR({$c->options['length']})",
            'char' => "CHAR({$c->options['length']})",
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'tinyInteger' => 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'decimal' => "DECIMAL({$c->options['precision']},{$c->options['scale']})",
            'double' => 'DOUBLE',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime' => 'DATETIME',
            'json' => 'JSON',
            'uuid' => 'CHAR(36)',
            'binary' => 'LONGBLOB',
            'enum' => 'VARCHAR(255)',
        };
    }

    protected function modifiers(ColumnDefinition $c): string
    {
        $sql = '';
        if ($c->unsigned && in_array($c->type, ['tinyInteger', 'smallInteger', 'integer', 'bigInteger'], true)) {
            $sql .= ' UNSIGNED';
        }
        if ($c->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }

    protected function columnSql(ColumnDefinition $column, bool $creating): string
    {
        $sql = parent::columnSql($column, $creating);

        return $column->comment !== null ? $sql . ' COMMENT ' . $this->literal($column->comment) : $sql;
    }

    protected function tableSuffix(array $options): string
    {
        $charset = preg_replace('/[^a-z0-9_]/i', '', (string) ($options['charset'] ?? 'utf8mb4'));
        $collation = preg_replace('/[^a-z0-9_]/i', '', (string) ($options['collation'] ?? 'utf8mb4_unicode_ci'));

        return " ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
    }

    protected function dropIndexSql(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name) . ' ON ' . $this->wrap($table);
    }

    protected function dropForeignSql(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP FOREIGN KEY ' . $this->wrap($name);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->wrap($from) . ' TO ' . $this->wrap($to);
    }

    public function compileHasTable(string $table): array
    {
        return ['SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]];
    }

    public function compileColumns(string $table): array
    {
        return ['SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$table]];
    }

    public function compileIndexes(string $table): array
    {
        return ['SELECT DISTINCT index_name AS name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name', [$table]];
    }

    public function compileTables(): array
    {
        return ["SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name", []];
    }

    public function compileDisableForeignKeys(): ?string
    {
        return 'SET FOREIGN_KEY_CHECKS=0';
    }

    public function compileEnableForeignKeys(): ?string
    {
        return 'SET FOREIGN_KEY_CHECKS=1';
    }
}
