<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm;

use BadMethodCallException;
use Closure;
use Generator;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use LogicException;

/**
 * A query that returns models. Builder methods (where, orderBy, join...)
 * pass through to the QueryBuilder; scopes are called by name.
 *
 *   Entry::query()->with('author', 'terms')->published()->whereHas('terms', fn($q) => $q->where('slug', 'news'))->paginate(10);
 *
 * Soft-deleted rows are left out unless withTrashed()/onlyTrashed().
 *
 * @template TModel of Model
 * @mixin QueryBuilder
 * @api
 */
final class ModelQuery
{
    /** Builder methods that read a result: run on the scoped copy. */
    private const array TERMINAL = ['count', 'max', 'min', 'sum', 'avg', 'exists', 'value', 'pluck', 'toSql', 'getBindings', 'state'];

    /** Builder methods that would skip models (mass assignment, events, soft deletes). */
    private const array BLOCKED = ['insert', 'insertMany', 'upsert', 'truncate', 'table', 'from', 'lazyById', 'connection'];

    /** @var array<string, Closure|null> */
    private array $eager = [];
    private bool $withTrashed = false;
    private bool $onlyTrashed = false;

    public function __construct(
        private readonly Model $model,
        private QueryBuilder $builder,
    ) {}

    /** @return TModel */
    public function getModel(): Model
    {
        return $this->model;
    }

    /** The plain query, with the soft-delete condition applied. */
    public function toBase(): QueryBuilder
    {
        $builder = clone $this->builder;
        if ($this->model->usesSoftDeletes() && !$this->withTrashed) {
            $column = $this->model->getTable() . '.' . Model::DELETED_AT;
            $builder->groupConditions();
            $this->onlyTrashed ? $builder->whereNotNull($column) : $builder->whereNull($column);
        }

        return $builder;
    }

    // ── eager loading, soft deletes, relations ───────────────────────

    public function with(string|array ...$relations): self
    {
        $this->eager = array_merge($this->eager, EagerLoader::parse($relations));

        return $this;
    }

    /** @internal parsed map (nested eager loads) */
    public function withMap(array $map): self
    {
        $this->eager = array_merge($this->eager, $map);

        return $this;
    }

    public function without(string ...$relations): self
    {
        foreach ($relations as $name) {
            foreach (array_keys($this->eager) as $key) {
                if ($key === $name || str_starts_with($key, $name . '.')) {
                    unset($this->eager[$key]);
                }
            }
        }

        return $this;
    }

    public function withTrashed(bool $with = true): self
    {
        $this->withTrashed = $with;
        $this->onlyTrashed = false;

        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->withTrashed = false;
        $this->onlyTrashed = true;

        return $this;
    }

    public function whereKey(mixed $id): self
    {
        $column = $this->model->getQualifiedKeyName();
        is_array($id) ? $this->builder->whereIn($column, array_values($id)) : $this->builder->where($column, $id);

        return $this;
    }

    public function whereKeyNot(mixed $id): self
    {
        $column = $this->model->getQualifiedKeyName();
        is_array($id) ? $this->builder->whereNotIn($column, array_values($id)) : $this->builder->where($column, '!=', $id);

        return $this;
    }

    /**
     * Rows with at least one related row (matching $callback):
     * whereHas('terms', fn(ModelQuery $q) => $q->where('slug', 'news')). Dots nest.
     */
    public function whereHas(string $relation, ?Closure $callback = null, bool $not = false, string $boolean = 'AND'): self
    {
        if (str_contains($relation, '.')) {
            [$first, $rest] = explode('.', $relation, 2);

            return $this->whereHas($first, static fn(ModelQuery $q) => $q->whereHas($rest, $callback), $not, $boolean);
        }
        $rel = $this->model->relationNamed($relation)
            ?? throw new BadMethodCallException(sprintf('Call to undefined relation [%s] on %s.', $relation, $this->model::class));
        if ($rel->getRelated()->getTable() === $this->model->getTable()) {
            throw new LogicException("whereHas() on [{$relation}] relates {$this->model->getTable()} to itself, which isn't supported; use a join.");
        }
        $this->builder->whereExistsQuery($rel->existenceBuilder($callback), $not, $boolean);

        return $this;
    }

    public function orWhereHas(string $relation, ?Closure $callback = null): self
    {
        return $this->whereHas($relation, $callback, false, 'OR');
    }

    public function whereDoesntHave(string $relation, ?Closure $callback = null): self
    {
        return $this->whereHas($relation, $callback, true);
    }

    public function has(string $relation): self
    {
        return $this->whereHas($relation);
    }

    public function doesntHave(string $relation): self
    {
        return $this->whereHas($relation, null, true);
    }

    /** Like QueryBuilder::when(), but the callback gets this ModelQuery (scopes work). */
    public function when(mixed $condition, Closure $callback, ?Closure $default = null): self
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }

        return $this;
    }

    // ── reading ──────────────────────────────────────────────────────

    /** @return ModelCollection<TModel> */
    public function get(): ModelCollection
    {
        return $this->hydrate($this->toBase()->get());
    }

    /** @return TModel|null */
    public function first(): ?Model
    {
        $row = $this->toBase()->first();

        return $row === null ? null : $this->hydrate([$row])->first();
    }

    /** @return TModel */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException('No ' . $this->model::class . ' matches the query.');
    }

    /** @return TModel|null */
    public function find(int|string $id): ?Model
    {
        $query = clone $this;
        $query->builder->groupConditions();

        return $query->whereKey($id)->first();
    }

    /** @return TModel */
    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw new ModelNotFoundException($this->model::class . " [{$id}] not found.");
    }

    /** @return ModelCollection<TModel> */
    public function findMany(array $ids): ModelCollection
    {
        return $ids === [] ? new ModelCollection() : (clone $this)->whereKey($ids)->get();
    }

    /** The first match, or a new unsaved model with $attributes + $values (not mass assigned). */
    public function firstOrNew(array $attributes, array $values = []): Model
    {
        return (clone $this)->whereAll($attributes)->first() ?? $this->model->newInstance()->forceFill($attributes + $values);
    }

    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        $model = $this->firstOrNew($attributes, $values);
        if (!$model->exists()) {
            $model->save();
        }

        return $model;
    }

    /** $values are written with forceFill: pass only trusted data. */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $model = $this->firstOrNew($attributes);
        $model->forceFill($values)->save();

        return $model;
    }

    /**
     * @return array{data: ModelCollection<TModel>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $result = $this->toBase()->paginate($perPage, $page);
        $result['data'] = $this->hydrate($result['data']);

        return $result;
    }

    /** @param Closure(ModelCollection<TModel>): (bool|void) $callback */
    public function chunkById(int $size, Closure $callback): void
    {
        foreach ($this->chunks($size) as $chunk) {
            if ($callback($chunk) === false) {
                return;
            }
        }
    }

    /**
     * Iterate models in key-ordered chunks (constant memory; eager loads per chunk).
     *
     * @return Generator<int, TModel>
     */
    public function lazy(int $chunk = 500): Generator
    {
        foreach ($this->chunks($chunk) as $models) {
            yield from $models->all();
        }
    }

    /** @return Generator<int, ModelCollection<TModel>> */
    private function chunks(int $size): Generator
    {
        $key = $this->model->getQualifiedKeyName();
        $alias = $this->model->getKeyName();
        $last = null;
        do {
            $q = $this->toBase()->orderBy($key)->limit(max(1, $size));
            if ($last !== null) {
                $q->where($key, '>', $last);
            }
            $rows = $q->get();
            if ($rows !== []) {
                $last = end($rows)[$alias];
                yield $this->hydrate($rows);
            }
        } while (count($rows) === $size);
    }

    // ── bulk writes (no model events) ────────────────────────────────

    /** Update every matching row. Values pass through the model's casts. */
    public function update(array $values): int
    {
        $raw = $this->model->castForStorage($values);
        if ($this->model->usesTimestamps() && !array_key_exists(Model::UPDATED_AT, $raw)) {
            $raw[Model::UPDATED_AT] = Model::freshTimestamp();
        }

        return $this->toBase()->update($raw);
    }

    /** Delete every matching row (soft delete when the model uses it). */
    public function delete(): int
    {
        if ($this->model->usesSoftDeletes()) {
            return $this->toBase()->update([Model::DELETED_AT => Model::freshTimestamp()]);
        }

        return $this->toBase()->delete();
    }

    public function forceDelete(): int
    {
        return $this->toBase()->delete();
    }

    public function restore(): int
    {
        return (clone $this)->onlyTrashed()->toBase()->update([Model::DELETED_AT => null]);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        if ($this->model->usesTimestamps()) {
            $extra += [Model::UPDATED_AT => Model::freshTimestamp()];
        }

        return $this->toBase()->increment($column, $amount, $this->model->castForStorage($extra));
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    // ── plumbing ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    private function whereAll(array $attributes): self
    {
        foreach ($this->model->castForStorage($attributes) as $column => $value) {
            $value === null ? $this->builder->whereNull($column) : $this->builder->where($column, $value);
        }

        return $this;
    }

    /** @param list<array<string, mixed>> $rows */
    private function hydrate(array $rows): ModelCollection
    {
        $models = [];
        $batch = count($rows) > 1;
        foreach ($rows as $row) {
            $models[] = $this->model->newFromBuilder($row, $batch);
        }
        if ($models !== [] && $this->eager !== []) {
            EagerLoader::load($models, $this->eager);
        }

        return new ModelCollection($models);
    }

    public function __call(string $method, array $arguments): mixed
    {
        if ($this->model->hasScope($method)) {
            $result = $this->model->callScope($method, $this, $arguments);

            return $result === null || $result instanceof self ? $this : $result;
        }
        if (in_array($method, self::BLOCKED, true) || !method_exists($this->builder, $method)) {
            throw new BadMethodCallException(sprintf('Call to undefined method %s::%s() (no scope or query method of that name on %s).', self::class, $method, $this->model::class));
        }
        if (in_array($method, self::TERMINAL, true)) {
            return $this->toBase()->{$method}(...$arguments);
        }
        $result = $this->builder->{$method}(...$arguments);

        return $result instanceof QueryBuilder ? $this : $result;
    }

    public function __clone()
    {
        $this->builder = clone $this->builder;
    }
}
