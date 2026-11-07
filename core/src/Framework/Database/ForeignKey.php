<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use InvalidArgumentException;

/**
 * A foreign key in a Blueprint.
 *
 * @api
 */
final class ForeignKey
{
    private const array ACTIONS = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];

    public string $referencedTable = '';
    public string $referencedColumn = 'id';
    public string $onDelete = 'RESTRICT';
    public string $onUpdate = 'RESTRICT';

    public function __construct(
        public readonly string $table,
        public readonly string $column,
    ) {}

    public function name(): string
    {
        return "fk_{$this->table}_{$this->column}";
    }

    public function references(string $column): static
    {
        $this->referencedColumn = $column;

        return $this;
    }

    public function on(string $table): static
    {
        $this->referencedTable = $table;

        return $this;
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    private static function action(string $action): string
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Invalid foreign key action [{$action}].");
        }

        return $action;
    }
}
