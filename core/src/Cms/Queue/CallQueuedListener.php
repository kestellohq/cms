<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue;

use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Events\EventDispatcher;

/**
 * Runs a #[Queued] event listener in the background. The event is rebuilt
 * from its constructor arguments, and the listener only runs if it is
 * still registered as a queued listener of that event; it runs as the
 * package that registered it.
 *
 * @internal pushed by the Kernel's event queuer
 */
final class CallQueuedListener implements Job
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $listener,
        public readonly string $event,
        public readonly array $data,
    ) {}

    public function retries(): int
    {
        return 3;
    }

    public function handle(EventDispatcher $events): void
    {
        if (!class_exists($this->event)) {
            throw new \RuntimeException("Event class [{$this->event}] no longer exists.");
        }
        $event = new ($this->event)(...$this->data);
        $owner = $events->queuedListenerOwner($event, $this->listener)
            ?? throw new \RuntimeException("[{$this->listener}] is not a queued listener of {$this->event} (is its plugin active?).");

        $events->runListener($this->listener, $event, $owner);
    }
}
