<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Closure;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\QueryBuilder;

/**
 * A relation between a parent model and related models. It is also a
 * query: $post->comments()->where('approved', true)->latest()->get().
 *
 * The parent's own constraint is added when the query first runs, so the
 * same definition serves eager loading (constraints for many parents).
 *
 * @mixin ModelQuery
 * @api
 */
abstract class Relation
{
    private bool $constrained = false;

    public function __construct(
        protected ModelQuery $query,
        protected Model $parent,
    ) {}

    /** Constrain the query to this one parent. */
    abstract protected function addConstraints(): void;

    /** @param list<Model> $models constrain the query to all these parents */
    abstract public function addEagerConstraints(array $models): void;

    /** @param list<Model> $models put the loaded results on each parent */
    abstract public function match(array $models, ModelCollection $results, string $name): void;

    /** The relation's value for this parent: a model, null or a collection. */
    abstract public function getResults(): mixed;

    /** Add to $sub (a query on the related table) the condition linking it to the parent table. */
    abstract public function existenceQuery(ModelQuery $sub): ModelQuery;

    /** Empty value for a parent with nothing related. */
    abstract public function emptyValue(): mixed;

    public function getQuery(): ModelQuery
    {
        if (!$this->constrained) {
            $this->constrained = true;
            $this->addConstraints();
        }

        return $this->query;
    }

    public function getParent(): Model
    {
        return $this->parent;
    }

    public function getRelated(): Model
    {
        return $this->query->getModel();
    }

    public function get(): ModelCollection
    {
        return $this->fetch($this->getQuery());
    }

    public function first(): ?Model
    {
        return $this->fetch((clone $this->getQuery())->limit(1))->first();
    }

    /**
     * @internal EagerLoader
     * @param list<Model> $models
     * @param array<string, Closure|null> $nested
     */
    public function eagerLoad(array $models, string $name, ?Closure $constraint, array $nested): void
    {
        $this->constrained = true;
        $this->addEagerConstraints($models);
        if ($constraint !== null) {
            $constraint($this);
        }
        if ($nested !== []) {
            $this->query->withMap($nested);
        }
        $this->match($models, $this->fetch($this->query), $name);
    }

    /** Run the query. BelongsToMany overrides this to read pivot columns. */
    protected function fetch(ModelQuery $query): ModelCollection
    {
        return $query->get();
    }

    /** Proxy to the query; builder calls return the relation itself. */
    public function __call(string $method, array $arguments): mixed
    {
        $query = $this->getQuery();
        $result = $query->{$method}(...$arguments);

        return $result === $query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @param list<Model> $models
     * @return list<int|string>
     */
    protected static function keys(array $models, string $attribute): array
    {
        $keys = [];
        foreach ($models as $model) {
            $v = $model->getRawAttribute($attribute);
            if ($v !== null && $v !== '') {
                $keys[(string) $v] = $v;
            }
        }

        return array_values($keys);
    }

    protected static function qualify(Model $model, string $column): string
    {
        return $model->getTable() . '.' . $column;
    }

    /** @internal ModelQuery::whereHas: the related-table subquery as a plain builder. */
    public function existenceBuilder(?Closure $callback): QueryBuilder
    {
        $sub = $this->getRelated()->newQuery();
        if ($callback !== null) {
            $callback($sub);
        }

        return $this->existenceQuery($sub)->toBase();
    }
}
