<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Closure;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelQuery;
use LogicException;

/**
 * The other side of morphMany/morphOne: $comment->commentable reads
 * commentable_type (a morph-map alias) and commentable_id. Types that
 * aren't in the morph map resolve to null; class names stored in the
 * column are never instantiated.
 *
 * Eager-load constraints for morphTo receive each type's ModelQuery.
 *
 * @api
 */
final class MorphTo extends Relation
{
    public function __construct(
        ModelQuery $query,
        Model $child,
        private readonly string $typeColumn,
        private readonly string $idColumn,
        private readonly string $name,
        private readonly bool $resolved,
    ) {
        parent::__construct($query, $child);
    }

    protected function addConstraints(): void
    {
        $this->query->where($this->getRelated()->getQualifiedKeyName(), $this->parent->getRawAttribute($this->idColumn));
    }

    public function getResults(): ?Model
    {
        return !$this->resolved || $this->parent->getRawAttribute($this->idColumn) === null ? null : $this->first();
    }

    public function emptyValue(): mixed
    {
        return null;
    }

    public function eagerLoad(array $models, string $name, ?Closure $constraint, array $nested): void
    {
        $byType = [];
        foreach ($models as $model) {
            $model->setRelation($name, null);
            $type = (string) $model->getRawAttribute($this->typeColumn);
            if ($type !== '' && $model->getRawAttribute($this->idColumn) !== null) {
                $byType[$type][] = $model;
            }
        }
        $registry = Model::registry();
        foreach ($byType as $type => $group) {
            $class = $registry->morphClass($type);
            if ($class === null) {
                continue;
            }
            $instance = new $class();
            $query = $instance->newQuery()->whereIn($instance->getQualifiedKeyName(), self::keys($group, $this->idColumn));
            if ($constraint !== null) {
                $constraint($query);
            }
            if ($nested !== []) {
                $query->withMap($nested);
            }
            $found = [];
            foreach ($query->get() as $result) {
                $found[(string) $result->getKey()] = $result;
            }
            foreach ($group as $model) {
                $model->setRelation($name, $found[(string) $model->getRawAttribute($this->idColumn)] ?? null);
            }
        }
    }

    public function addEagerConstraints(array $models): void
    {
        throw new LogicException('morphTo eager loads per type (see eagerLoad).');
    }

    public function match(array $models, ModelCollection $results, string $name): void
    {
        throw new LogicException('morphTo eager loads per type (see eagerLoad).');
    }

    public function existenceQuery(ModelQuery $sub): ModelQuery
    {
        throw new LogicException('whereHas() is not supported on morphTo relations; query each type instead.');
    }

    /** Point at $model (its class must be in the morph map). Save the child afterwards. */
    public function associate(?Model $model): Model
    {
        $this->parent->setAttribute($this->typeColumn, $model?->getMorphClass());
        $this->parent->setAttribute($this->idColumn, $model?->getKey());
        $this->parent->setRelation($this->name, $model);

        return $this->parent;
    }

    public function dissociate(): Model
    {
        return $this->associate(null);
    }
}
