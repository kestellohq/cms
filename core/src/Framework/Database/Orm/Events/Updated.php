<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * After a model was updated. $event->model is the model.
 *
 * @api
 */
final class Updated extends ModelEvent {}
