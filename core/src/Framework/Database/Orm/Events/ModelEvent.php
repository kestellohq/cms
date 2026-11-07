<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

use Kestello\Framework\Database\Model;

/**
 * Base of every model lifecycle event. Listen to one kind for all models
 * ($events->on(Saved::class, ...)) or to one model class through
 * $cms->models->on(Entry::class, 'saved', ...).
 *
 * @api
 */
abstract class ModelEvent
{
    /** @param Model $model The model being written. */
    public function __construct(public readonly Model $model) {}

    /** The short name: creating, saved, deleted... */
    public function name(): string
    {
        return lcfirst((new \ReflectionClass($this))->getShortName());
    }
}
