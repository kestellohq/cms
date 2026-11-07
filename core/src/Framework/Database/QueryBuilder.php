<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use Closure;
use Generator;
use InvalidArgumentException;

/**
 * Fluent, dialect-independent query builder. Values are always bound;
 * identifiers are validated and quoted by the connection's grammar.
 *
 *   QueryBuilder::table('users')
 *       ->select('id', 'email')
 *       ->where('is_active', true)
 *       ->where(fn($q) => $q->where('role', 'admin')->orWhere('role', 'editor'))
 *       ->whereLike('email', '%@example.com')
 *       ->orderByDesc('id')
 *       ->limit(20)
 *       ->get();
 *
 * @api
 */
class QueryBuilder
{
    private const array OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    private string $table = '';
    /** @var list<string|Expression> */
    private array $columns = ['*'];
    private bool $distinct = false;
    /** @var list<array<string, mixed>> */
    private array $wheres = [];
    /** @var list<array<string, mixed>> */
    private array $havings = [];
    /** @var list<array<string, mixed>> */
    private array $orders = [];
    /** @var list<string> */
    private array $groups = [];
    /** @var list<array{type: string, table: string, wheres: list<array<string, mixed>>}> */
    private array $joins = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private ?string $lock = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public static function table(string $table, ?Connection $connection = null): static
    {
        $builder = new static($connection ?? DatabaseManager::getConnection());
        $builder->table = $table;

        return $builder;
    }

    /** Set the table (for subqueries built in closures). */
    public function from(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    /** A raw SQL fragment (developer-written SQL only). */
    public static function raw(string $sql, array $bindings = []): Expression
    {
        return new Expression($sql, $bindings);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    // ── Select ──────────────────────────────────────────────────────

    public function select(string|Expression ...$columns): static
    {
        $this->columns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    public function addSelect(string|Expression ...$columns): static
    {
        $this->columns = $this->columns === ['*'] ? array_values($columns) : [...$this->columns, ...$columns];

        return $this;
    }

    public function selectRaw(string $sql, array $bindings = []): static
    {
        return $this->addSelect(new Expression($sql, $bindings));
    }

    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    // ── Where ───────────────────────────────────────────────────────

    /**
     * where('col', value) · where('col', '>=', value) · where(fn($q) => ...) for a nested group.
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        return $this->basicWhere($column, $operator, $value, $boolean, func_num_args() >= 3);
    }

    /** $hasValue: the caller passed (column, operator, value), not (column, value). */
    private function basicWhere(string $column, mixed $operator, mixed $value, string $boolean, bool $hasValue): static
    {
        if (!$hasValue || ($value === null && !is_string($operator)) || ($value === null && !self::isOperator($operator))) {
            [$value, $operator] = [$operator, '='];
        }

        if ($value === null) {
            return $this->addWhere(['type' => 'null', 'column' => $column, 'not' => in_array($operator, ['!=', '<>'], true)], $boolean);
        }

        return $this->addWhere(['type' => 'basic', 'column' => $column, 'operator' => self::operator($operator), 'value' => $value], $boolean);
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, 'OR');
        }

        return $this->basicWhere($column, $operator, $value, 'OR', func_num_args() >= 3);
    }

    /**
     * Wrap the conditions so far in one group when any of them is an OR, so
     * a condition added next applies to all of them: (a OR b) AND id = ?,
     * never a OR b AND id = ?. Used before adding framework conditions
     * (find, chunk cursors, soft-delete and relation scopes).
     */
    public function groupConditions(): static
    {
        foreach ($this->wheres as $i => $where) {
            if ($i > 0 && $where['boolean'] === 'OR') {
                $this->wheres = [['type' => 'nested', 'wheres' => $this->wheres, 'boolean' => 'AND']];
                break;
            }
        }

        return $this;
    }

    /** AND (col1 op value OR col2 op value …): match any of the columns. */
    public function whereAny(array $columns, string $operator, mixed $value): static
    {
        if ($columns === []) {
            return $this;
        }

        return $this->where(function (self $q) use ($columns, $operator, $value): void {
            foreach ($columns as $column) {
                $q->orWhere($column, $operator, $value);
            }
        });
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        return $this->addWhere(['type' => 'column', 'first' => $first, 'operator' => self::operator($operator), 'second' => $second], $boolean);
    }

    public function whereIn(string $column, array $values, bool $not = false, string $boolean = 'AND'): static
    {
        return $this->addWhere(['type' => 'in', 'column' => $column, 'values' => array_values($values), 'not' => $not], $boolean);
    }

    public function whereNotIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, true);
    }

    public function whereNull(string $column, bool $not = false, string $boolean = 'AND'): static
    {
        return $this->addWhere(['type' => 'null', 'column' => $column, 'not' => $not], $boolean);
    }

    public function whereNotNull(string $column): static
    {
        return $this->whereNull($column, true);
    }

    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, false, 'OR');
    }

    public function whereBetween(string $column, mixed $from, mixed $to, bool $not = false): static
    {
        return $this->addWhere(['type' => 'between', 'column' => $column, 'from' => $from, 'to' => $to, 'not' => $not], 'AND');
    }

    /**
     * Case-insensitive pattern match in every dialect. Escape user input
     * with escapeLike() and add your own % wildcards.
     */
    public function whereLike(string $column, string $pattern, bool $not = false, string $boolean = 'AND'): static
    {
        return $this->addWhere(['type' => 'like', 'column' => $column, 'value' => $pattern, 'not' => $not], $boolean);
    }

    public function orWhereLike(string $column, string $pattern): static
    {
        return $this->whereLike($column, $pattern, false, 'OR');
    }

    /** @param Closure(self): void $callback builds the subquery (table() it yourself) */
    public function whereExists(Closure $callback, bool $not = false): static
    {
        $sub = new static($this->connection);
        $callback($sub);

        return $this->addWhere(['type' => 'exists', 'query' => $sub->state(), 'not' => $not], 'AND');
    }

    /** Like whereExists(), with a subquery built elsewhere (the ORM's whereHas). */
    public function whereExistsQuery(self $sub, bool $not = false, string $boolean = 'AND'): static
    {
        return $this->addWhere(['type' => 'exists', 'query' => $sub->state(), 'not' => $not], $boolean);
    }

    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        return $this->addWhere(['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings)], $boolean);
    }

    private function whereNested(Closure $callback, string $boolean): static
    {
        $nested = new static($this->connection);
        $nested->table = $this->table;
        $callback($nested);

        return $nested->wheres === [] ? $this : $this->addWhere(['type' => 'nested', 'wheres' => $nested->wheres], $boolean);
    }

    /** @param array<string, mixed> $where */
    private function addWhere(array $where, string $boolean): static
    {
        $where['boolean'] = $boolean === 'OR' ? 'OR' : 'AND';
        $this->wheres[] = $where;

        return $this;
    }

    // ── Joins, grouping, ordering ───────────────────────────────────

    /**
     * join('posts', 'posts.user_id', '=', 'users.id') or
     * join('posts', fn(QueryBuilder $j) => $j->whereColumn('posts.user_id', '=', 'users.id')->where('posts.published', true))
     */
    public function join(string $table, string|Closure $first, ?string $operator = null, ?string $second = null, string $type = 'INNER'): static
    {
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new InvalidArgumentException("Invalid join type \"{$type}\".");
        }

        $on = new static($this->connection);
        if ($first instanceof Closure) {
            $first($on);
        } elseif ($operator !== null && $second !== null) {
            $on->whereColumn($first, $operator, $second);
        }

        $this->joins[] = ['type' => $type, 'table' => $table, 'wheres' => $on->wheres];

        return $this;
    }

    public function leftJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function groupBy(string ...$columns): static
    {
        $this->groups = [...$this->groups, ...$columns];

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): static
    {
        $this->havings[] = ['type' => 'basic', 'column' => $column, 'operator' => self::operator($operator), 'value' => $value, 'boolean' => 'AND'];

        return $this;
    }

    public function havingRaw(string $sql, array $bindings = []): static
    {
        $this->havings[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => 'AND'];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orders[] = ['column' => $column, 'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'];

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function inRandomOrder(): static
    {
        $this->orders[] = ['random' => true];

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limitValue = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offsetValue = max(0, $offset);

        return $this;
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(max(0, $page - 1) * $perPage)->limit($perPage);
    }

    /** SELECT … FOR UPDATE (inside a transaction). No-op on SQLite. */
    public function lockForUpdate(): static
    {
        $this->lock = 'update';

        return $this;
    }

    public function sharedLock(): static
    {
        $this->lock = 'share';

        return $this;
    }

    /** Apply $callback when $condition is truthy. */
    public function when(mixed $condition, Closure $callback, ?Closure $default = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }

        return $this;
    }

    // ── Reading ─────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();

        return $this->connection->query($sql, $bindings)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limitValue = 1;
        [$sql, $bindings] = $clone->compileSelect();

        $row = $this->connection->query($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int|string $id, string $key = 'id'): ?array
    {
        return (clone $this)->groupConditions()->where($key, $id)->first();
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        return $row === null ? null : reset($row);
    }

    /** @return list<mixed>|array<array-key, mixed> values, keyed by $key when given */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = (clone $this)->select(...array_filter([$column, $key]))->get();
        $valueKey = self::alias($column);

        if ($key === null) {
            return array_column($rows, $valueKey);
        }

        return array_column($rows, $valueKey, self::alias($key));
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function sum(string $column): int|float
    {
        return +($this->aggregate('SUM', $column) ?? 0);
    }

    public function avg(string $column): ?float
    {
        $value = $this->aggregate('AVG', $column);

        return $value === null ? null : (float) $value;
    }

    public function exists(): bool
    {
        return (clone $this)->select(new Expression('1'))->first() !== null;
    }

    /**
     * Process rows in chunks ordered by id (safe while updating rows).
     * Return false from the callback to stop.
     *
     * @param Closure(list<array<string, mixed>>): (bool|void) $callback
     */
    public function chunkById(int $size, Closure $callback, string $column = 'id'): void
    {
        $last = null;

        do {
            $query = (clone $this)->groupConditions()->orderBy($column)->limit($size);
            if ($last !== null) {
                $query->where($column, '>', $last);
            }
            $rows = $query->get();

            if ($rows === [] || $callback($rows) === false) {
                return;
            }

            $last = end($rows)[self::alias($column)];
        } while (count($rows) === $size);
    }

    /**
     * Iterate rows lazily in id-ordered chunks (constant memory).
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function lazyById(int $chunk = 500, string $column = 'id'): Generator
    {
        $last = null;

        do {
            $query = (clone $this)->groupConditions()->orderBy($column)->limit($chunk);
            if ($last !== null) {
                $query->where($column, '>', $last);
            }
            $rows = $query->get();

            foreach ($rows as $row) {
                yield $row;
            }

            $last = $rows === [] ? null : end($rows)[self::alias($column)];
        } while (count($rows) === $chunk);
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $page = max(1, $page);
        $total = (clone $this)->count();

        return [
            'data' => (clone $this)->forPage($page, $perPage)->get(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    // ── Writing ─────────────────────────────────────────────────────

    /**
     * Insert one row. Returns the generated id ("id" column), or 0 when
     * the table has none.
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values, string $key = 'id'): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('insert() needs at least one column.');
        }

        $grammar = $this->connection->grammar();
        [$sql, $bindings] = $grammar->compileInsert($this->table, [$values]);

        // Same answer on every dialect for tables without the key column
        // (SQLite would otherwise report its internal rowid).
        if (!$this->hasColumn($key)) {
            $this->connection->query($sql, $bindings);

            return 0;
        }

        $returning = $grammar->compileReturning($key);
        if ($returning === '') {
            $this->connection->query($sql, $bindings);

            return (int) $this->connection->lastInsertId();
        }

        $row = $this->connection->query($sql . $returning, $bindings)->fetch();

        return (int) ($row[$key] ?? 0);
    }

    /** @param list<array<string, mixed>> $rows */
    public function insertMany(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            [$sql, $bindings] = $this->connection->grammar()->compileInsert($this->table, $chunk);
            $this->connection->query($sql, $bindings);
        }

        return true;
    }

    /**
     * Insert rows, or update $update columns where $uniqueBy already exists.
     * $uniqueBy must match a unique index. Returns affected rows (driver-specific).
     *
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     * @param list<string> $uniqueBy
     * @param list<string>|null $update columns to overwrite (default: all except $uniqueBy)
     */
    public function upsert(array $rows, array $uniqueBy, ?array $update = null): int
    {
        if ($rows === []) {
            return 0;
        }
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }

        $update ??= array_values(array_diff(array_keys($rows[0]), $uniqueBy));
        [$sql, $bindings] = $this->connection->grammar()->compileUpsert($this->table, $rows, $uniqueBy, $update);

        return $this->connection->query($sql, $bindings)->rowCount();
    }

    /** @param array<string, mixed> $values column => value or Expression */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        [$sql, $bindings] = $this->connection->grammar()->compileUpdate($this->state(), $values);

        return $this->connection->query($sql, $bindings)->rowCount();
    }

    /** @param array<string, mixed> $extra other columns to set */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->connection->grammar()->wrap($column);

        return $this->update([$column => new Expression("{$wrapped} + ?", [$amount])] + $extra);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->connection->grammar()->wrap($column);

        return $this->update([$column => new Expression("{$wrapped} - ?", [$amount])] + $extra);
    }

    public function delete(): int
    {
        [$sql, $bindings] = $this->connection->grammar()->compileDelete($this->state());

        return $this->connection->query($sql, $bindings)->rowCount();
    }

    /** Remove all rows and reset auto-increment counters. */
    public function truncate(): void
    {
        foreach ($this->connection->grammar()->compileTruncate($this->table) as $i => $sql) {
            try {
                $this->connection->raw($sql);
            } catch (\PDOException $e) {
                if ($i === 0) {
                    throw $e;
                }
                // Follow-up statements (SQLite sequence reset) are best effort.
            }
        }
    }

    // ── SQL ─────────────────────────────────────────────────────────

    /** The SELECT this builder would run (placeholders unbound). */
    public function toSql(): string
    {
        return $this->compileSelect()[0];
    }

    /** @return list<mixed> */
    public function getBindings(): array
    {
        return $this->compileSelect()[1];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function compileSelect(): array
    {
        return $this->connection->grammar()->compileSelect($this->state());
    }

    /**
     * @return array<string, mixed>
     * @internal for grammars and subqueries
     */
    public function state(): array
    {
        return [
            'table' => $this->table,
            'columns' => $this->columns,
            'distinct' => $this->distinct,
            'wheres' => $this->wheres,
            'havings' => $this->havings,
            'orders' => $this->orders,
            'groups' => $this->groups,
            'joins' => $this->joins,
            'limit' => $this->limitValue,
            'offset' => $this->offsetValue,
            'lock' => $this->lock,
        ];
    }

    private function aggregate(string $function, string $column): mixed
    {
        $clone = clone $this;
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;
        if ($clone->groups !== [] || $clone->distinct) {
            // Count the rows the query returns, not the rows of its first group.
            [$sql, $bindings] = $clone->compileSelect();
            $wrapped = $column === '*' ? '*' : $this->connection->grammar()->wrap(self::alias($column));
            $row = $this->connection->query("SELECT {$function}({$wrapped}) AS aggregate FROM ({$sql}) kst_sub", $bindings)->fetch();

            return $row === false ? null : ($row['aggregate'] ?? null);
        }
        $wrapped = $column === '*' ? '*' : $this->connection->grammar()->wrap($column);
        $clone->columns = [new Expression("{$function}({$wrapped}) AS aggregate")];

        $row = $clone->first();

        return $row['aggregate'] ?? null;
    }

    private function hasColumn(string $column): bool
    {
        return in_array($column, $this->connection->cachedColumns($this->table), true);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Quote an identifier for the default connection's dialect.
     * Prefer $connection->grammar()->wrap().
     */
    public static function wrap(string $identifier): string
    {
        $grammar = DatabaseManager::hasConnection() ? DatabaseManager::getConnection()->grammar() : new Grammar\MySqlGrammar();

        return $grammar->wrap($identifier);
    }

    /** Escape LIKE wildcards in a literal (append your own % afterwards). */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    private static function isOperator(mixed $operator): bool
    {
        return is_string($operator) && in_array(strtoupper(trim($operator)), self::OPERATORS, true);
    }

    private static function operator(mixed $operator): string
    {
        $op = strtoupper(trim((string) $operator));

        if (!in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid SQL operator \"{$operator}\".");
        }

        return $op;
    }

    /** Result key for a selected column ("users.id" → "id", "a as b" → "b"). */
    private static function alias(string $column): string
    {
        if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $column, $m) === 1) {
            return $m[1];
        }

        $parts = explode('.', $column);

        return (string) end($parts);
    }
}
