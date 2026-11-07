<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * Before a model is inserted, after saving. A listener may cancel the write. $event->model is the model.
 *
 * @api
 */
final class Creating extends CancellableModelEvent {}
