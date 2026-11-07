<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelCollection;

/** @api */
class HasOne extends HasOneOrMany
{
    public function match(array $models, ModelCollection $results, string $name): void
    {
        $groups = $this->group($results);
        foreach ($models as $model) {
            $model->setRelation($name, $groups[(string) $model->getRawAttribute($this->localKey)][0] ?? null);
        }
    }

    public function getResults(): ?Model
    {
        return $this->parent->getRawAttribute($this->localKey) === null ? null : $this->first();
    }

    public function emptyValue(): mixed
    {
        return null;
    }
}
