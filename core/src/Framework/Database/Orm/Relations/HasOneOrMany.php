<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelQuery;

/**
 * The related models hold the key: $user->posts (posts.author_id → users.id).
 */
abstract class HasOneOrMany extends Relation
{
    public function __construct(
        ModelQuery $query,
        Model $parent,
        protected readonly string $foreignKey,
        protected readonly string $localKey,
    ) {
        parent::__construct($query, $parent);
    }

    protected function addConstraints(): void
    {
        $this->query->where(self::qualify($this->getRelated(), $this->foreignKey), $this->parent->getRawAttribute($this->localKey));
        $this->extraConstraints($this->query);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(self::qualify($this->getRelated(), $this->foreignKey), self::keys($models, $this->localKey));
        $this->extraConstraints($this->query);
    }

    /** Morph relations add the type column. */
    protected function extraConstraints(ModelQuery $query): void {}

    /** @return array<string, list<Model>> */
    protected function group(ModelCollection $results): array
    {
        $groups = [];
        foreach ($results as $result) {
            $groups[(string) $result->getRawAttribute($this->foreignKey)][] = $result;
        }

        return $groups;
    }

    public function existenceQuery(ModelQuery $sub): ModelQuery
    {
        $sub->whereColumn(self::qualify($this->getRelated(), $this->foreignKey), '=', self::qualify($this->parent, $this->localKey));
        $this->extraConstraints($sub);

        return $sub;
    }

    /** Link $model to the parent and save it. */
    public function save(Model $model): Model
    {
        $this->link($model);
        $model->save();

        return $model;
    }

    /** Create a related model (attributes are mass assigned: fillable rules apply). */
    public function create(array $attributes = []): Model
    {
        $model = $this->getRelated()->newInstance();
        $model->fill($attributes);

        return $this->save($model);
    }

    /** @return list<Model> */
    public function createMany(array $rows): array
    {
        return array_map(fn(array $row) => $this->create($row), array_values($rows));
    }

    protected function link(Model $model): void
    {
        if ($this->parent->getRawAttribute($this->localKey) === null) {
            throw new \LogicException('Save the parent ' . $this->parent::class . ' before adding related models.');
        }
        $model->setAttribute($this->foreignKey, $this->parent->getRawAttribute($this->localKey));
    }
}
