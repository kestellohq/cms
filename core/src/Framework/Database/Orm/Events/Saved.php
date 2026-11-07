<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * After a model was inserted or updated, after created or updated. $event->model is the model.
 *
 * @api
 */
final class Saved extends ModelEvent {}
