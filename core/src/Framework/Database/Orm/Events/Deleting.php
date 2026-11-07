<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * Before a model is deleted (soft or for good). A listener may cancel the delete. $event->model is the model.
 *
 * @api
 */
final class Deleting extends CancellableModelEvent {}
