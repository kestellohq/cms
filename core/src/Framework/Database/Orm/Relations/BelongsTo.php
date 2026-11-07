<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelQuery;

/**
 * The child holds the key: $post->author (posts.author_id → users.id).
 *
 * @api
 */
final class BelongsTo extends Relation
{
    public function __construct(
        ModelQuery $query,
        Model $child,
        private readonly string $foreignKey,
        private readonly string $ownerKey,
        private readonly string $name,
    ) {
        parent::__construct($query, $child);
    }

    protected function addConstraints(): void
    {
        $this->query->where(self::qualify($this->getRelated(), $this->ownerKey), $this->parent->getRawAttribute($this->foreignKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(self::qualify($this->getRelated(), $this->ownerKey), self::keys($models, $this->foreignKey));
    }

    public function match(array $models, ModelCollection $results, string $name): void
    {
        $byKey = [];
        foreach ($results as $result) {
            $byKey[(string) $result->getRawAttribute($this->ownerKey)] = $result;
        }
        foreach ($models as $model) {
            $model->setRelation($name, $byKey[(string) $model->getRawAttribute($this->foreignKey)] ?? null);
        }
    }

    public function getResults(): ?Model
    {
        return $this->parent->getRawAttribute($this->foreignKey) === null ? null : $this->first();
    }

    public function emptyValue(): mixed
    {
        return null;
    }

    public function existenceQuery(ModelQuery $sub): ModelQuery
    {
        return $sub->whereColumn(self::qualify($this->getRelated(), $this->ownerKey), '=', self::qualify($this->parent, $this->foreignKey));
    }

    /** Point the child at $owner (or at nothing). Save the child afterwards. */
    public function associate(?Model $owner): Model
    {
        $this->parent->setAttribute($this->foreignKey, $owner?->getRawAttribute($this->ownerKey));
        $this->parent->setRelation($this->name, $owner);

        return $this->parent;
    }

    public function dissociate(): Model
    {
        return $this->associate(null);
    }
}
