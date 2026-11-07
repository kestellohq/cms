<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * After a model was deleted. $event->model is the model.
 *
 * @api
 */
final class Deleted extends ModelEvent {}
