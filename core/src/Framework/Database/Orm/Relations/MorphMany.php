<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelQuery;

/**
 * $entry->comments where comments.commentable_type = 'entry' and
 * comments.commentable_id = entries.id.
 *
 * @api
 */
final class MorphMany extends HasMany
{
    public function __construct(ModelQuery $query, Model $parent, private readonly string $typeColumn, string $idColumn, string $localKey)
    {
        parent::__construct($query, $parent, $idColumn, $localKey);
    }

    protected function extraConstraints(ModelQuery $query): void
    {
        $query->where(self::qualify($this->getRelated(), $this->typeColumn), $this->parent->getMorphClass());
    }

    protected function link(Model $model): void
    {
        parent::link($model);
        $model->setAttribute($this->typeColumn, $this->parent->getMorphClass());
    }
}
