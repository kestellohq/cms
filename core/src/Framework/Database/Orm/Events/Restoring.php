<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * Before a soft-deleted model is restored. A listener may cancel it. $event->model is the model.
 *
 * @api
 */
final class Restoring extends CancellableModelEvent {}
