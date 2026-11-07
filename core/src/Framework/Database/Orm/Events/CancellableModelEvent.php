<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Events;

/**
 * A "before" event (creating, updating, saving, deleting, restoring).
 * cancel() stops the write: save()/delete()/restore() return false and
 * later listeners are skipped.
 *
 * @api
 */
abstract class CancellableModelEvent extends ModelEvent implements \Kestello\Framework\Events\StoppableEvent
{
    private ?string $reason = null;

    public function cancel(string $reason = 'cancelled by a listener'): void
    {
        $this->reason = $reason;
    }

    public function cancelled(): bool
    {
        return $this->reason !== null;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isPropagationStopped(): bool
    {
        return $this->reason !== null;
    }
}
