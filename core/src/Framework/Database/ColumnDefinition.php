<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

/**
 * One column in a Blueprint. Types are logical ("string", "boolean",
 * "json"); each dialect's schema grammar maps them to native types.
 *
 * @api
 */
final class ColumnDefinition
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $useCurrent = false;
    public bool $unique = false;
    public bool $index = false;
    public bool $unsigned = false;
    public bool $autoIncrement = false;
    public bool $primary = false;
    public ?string $comment = null;

    /**
     * @param array<string, mixed> $options type options: length, precision, scale, values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $options = [],
        private readonly ?Blueprint $blueprint = null,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid column name [{$name}].");
        }
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;

        return $this;
    }

    /** A literal default (bound as a SQL literal by the grammar, never raw SQL). */
    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /** DEFAULT CURRENT_TIMESTAMP (timestamp/datetime columns). */
    public function useCurrent(): self
    {
        $this->useCurrent = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function index(): self
    {
        $this->index = true;

        return $this;
    }

    /** MySQL only; ignored by dialects without unsigned integers. */
    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Foreign key to $table.$column (foreignId columns):
     *   $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
     */
    public function constrained(string $table, string $column = 'id'): ForeignKey
    {
        if ($this->blueprint === null) {
            throw new \LogicException('constrained() needs a blueprint.');
        }

        return $this->blueprint->foreign($this->name)->references($column)->on($table);
    }
}
