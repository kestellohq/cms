<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * After a model was inserted. $event->model is the model.
 *
 * @api
 */
final class Created extends ModelEvent {}
