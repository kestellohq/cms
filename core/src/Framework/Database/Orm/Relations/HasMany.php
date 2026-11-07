<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Kestello\Framework\Database\Orm\ModelCollection;

/** @api */
class HasMany extends HasOneOrMany
{
    public function match(array $models, ModelCollection $results, string $name): void
    {
        $groups = $this->group($results);
        foreach ($models as $model) {
            $model->setRelation($name, new ModelCollection($groups[(string) $model->getRawAttribute($this->localKey)] ?? []));
        }
    }

    public function getResults(): ModelCollection
    {
        return $this->parent->getRawAttribute($this->localKey) === null ? new ModelCollection() : $this->get();
    }

    public function emptyValue(): mixed
    {
        return new ModelCollection();
    }
}
