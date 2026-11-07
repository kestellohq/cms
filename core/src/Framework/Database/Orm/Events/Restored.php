<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * After a soft-deleted model was restored. $event->model is the model.
 *
 * @api
 */
final class Restored extends ModelEvent {}
