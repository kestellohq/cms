<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use InvalidArgumentException;

/**
 * Describes a table to create, or changes to an existing table. Dialect
 * independent: the connection's schema grammar turns it into SQL.
 *
 *   $schema->create('posts', function (Blueprint $t) {
 *       $t->id();
 *       $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
 *       $t->string('title', 200);
 *       $t->json('meta')->nullable();
 *       $t->boolean('published')->default(false);
 *       $t->timestamps();
 *       $t->index('published', 'created_at');
 *   });
 *
 *   $schema->table('posts', fn(Blueprint $t) => $t->string('slug')->nullable());
 *
 * @api
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array{type: string, columns: list<string>, name: string}> */
    private array $indexes = [];

    /** @var list<ForeignKey> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $primary = [];

    /** @var list<array<string, mixed>> alter-table commands */
    private array $commands = [];

    public function __construct(
        public readonly string $table,
        public readonly bool $creating = true,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name [{$table}].");
        }
    }

    // ── Keys ────────────────────────────────────────────────────────

    /** Auto-incrementing BIGINT primary key. */
    public function id(string $name = 'id'): ColumnDefinition
    {
        $column = $this->add($name, 'bigInteger');
        $column->autoIncrement = true;
        $column->primary = true;
        $column->unsigned = true;

        return $column;
    }

    /** Auto-incrementing INT primary key. */
    public function increments(string $name = 'id'): ColumnDefinition
    {
        $column = $this->add($name, 'integer');
        $column->autoIncrement = true;
        $column->primary = true;
        $column->unsigned = true;

        return $column;
    }

    /** BIGINT column matching an id() key (unsigned on MySQL). */
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->add($name, 'bigInteger')->unsigned();
    }

    public function primary(string ...$columns): void
    {
        $this->primary = array_values($columns);
    }

    // ── Column types ────────────────────────────────────────────────

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add($name, 'string', ['length' => $length]);
    }

    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add($name, 'char', ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add($name, 'text');
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->add($name, 'mediumText');
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->add($name, 'longText');
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->add($name, 'tinyInteger');
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->add($name, 'smallInteger');
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->add($name, 'bigInteger');
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->add($name, 'boolean');
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->add($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->add($name, 'double');
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->add($name, 'date');
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->add($name, 'time');
    }

    /** Date and time without time zone. Store UTC. */
    public function datetime(string $name): ColumnDefinition
    {
        return $this->add($name, 'datetime');
    }

    /** Alias of datetime() (no MySQL TIMESTAMP 2038 limit or time-zone conversion). */
    public function timestamp(string $name): ColumnDefinition
    {
        return $this->add($name, 'datetime');
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->add($name, 'json');
    }

    public function uuid(string $name): ColumnDefinition
    {
        return $this->add($name, 'uuid');
    }

    public function ulid(string $name): ColumnDefinition
    {
        return $this->add($name, 'char', ['length' => 26]);
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->add($name, 'binary');
    }

    /** @param list<string> $values */
    public function enum(string $name, array $values): ColumnDefinition
    {
        if ($values === []) {
            throw new InvalidArgumentException('enum() needs at least one value.');
        }

        return $this->add($name, 'enum', ['values' => array_values(array_map('strval', $values))]);
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function softDeletes(): void
    {
        $this->timestamp('deleted_at')->nullable();
    }

    // ── Indexes and foreign keys ────────────────────────────────────

    public function index(string ...$columns): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => array_values($columns), 'name' => 'idx_' . $this->table . '_' . implode('_', $columns)];
    }

    public function uniqueIndex(string ...$columns): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => array_values($columns), 'name' => 'uniq_' . $this->table . '_' . implode('_', $columns)];
    }

    public function foreign(string $column): ForeignKey
    {
        $fk = new ForeignKey($this->table, $column);
        $this->foreignKeys[] = $fk;

        return $fk;
    }

    // ── Alter commands ──────────────────────────────────────────────

    public function dropColumn(string ...$columns): void
    {
        foreach ($columns as $column) {
            $this->commands[] = ['type' => 'dropColumn', 'column' => $column];
        }
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->commands[] = ['type' => 'renameColumn', 'from' => $from, 'to' => $to];
    }

    /** Drop an index by its columns (as created by index()). Unique ones from uniqueIndex() or ->unique() need dropUnique(). */
    public function dropIndex(string ...$columns): void
    {
        $this->commands[] = ['type' => 'dropIndex', 'name' => 'idx_' . $this->table . '_' . implode('_', $columns)];
    }

    public function dropUnique(string ...$columns): void
    {
        $this->commands[] = ['type' => 'dropIndex', 'name' => 'uniq_' . $this->table . '_' . implode('_', $columns)];
    }

    public function dropForeign(string $column): void
    {
        $this->commands[] = ['type' => 'dropForeign', 'name' => "fk_{$this->table}_{$column}"];
    }

    // ── Read by grammars ────────────────────────────────────────────

    /** @return list<ColumnDefinition> */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Explicit indexes plus the ones declared on columns (->index(), and
     * ->unique() when altering, since some dialects can't add UNIQUE inline).
     *
     * @return list<array{type: string, columns: list<string>, name: string}>
     */
    public function indexes(): array
    {
        $indexes = $this->indexes;
        foreach ($this->columns as $column) {
            if ($column->index) {
                $indexes[] = ['type' => 'index', 'columns' => [$column->name], 'name' => "idx_{$this->table}_{$column->name}"];
            }
            if ($column->unique && !$this->creating) {
                $indexes[] = ['type' => 'unique', 'columns' => [$column->name], 'name' => "uniq_{$this->table}_{$column->name}"];
            }
        }

        return $indexes;
    }

    /** @return list<ForeignKey> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** @return list<string> composite primary key columns, if any */
    public function primaryKey(): array
    {
        return $this->primary;
    }

    /** @return list<array<string, mixed>> */
    public function commands(): array
    {
        return $this->commands;
    }

    private function add(string $name, string $type, array $options = []): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type, $options, $this);
        $this->columns[] = $column;

        return $column;
    }
}
