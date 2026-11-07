<?php

declare(strict_types=1);

namespace Kestello\Framework\Events;

/**
 * Events implementing this can stop later listeners from running.
 *
 * @api
 */
interface StoppableEvent
{
    public function isPropagationStopped(): bool;
}
