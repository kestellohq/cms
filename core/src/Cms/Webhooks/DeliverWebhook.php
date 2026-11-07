<?php

declare(strict_types=1);

namespace Kestello\Cms\Webhooks;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;

/**
 * One attempt at one webhook delivery. Retries are not the queue's: a
 * failed attempt sets the delivery's next_attempt_at, and the
 * webhooks.retry task queues it again then (so it works the same on every
 * queue driver).
 *
 * @internal
 */
final class DeliverWebhook implements Job
{
    public function __construct(public readonly int $deliveryId) {}

    public function handle(Cms $cms): void
    {
        $cms->webhooks->attempt($this->deliveryId);
    }

    public function retries(): int
    {
        return 0;
    }
    public function timeout(): int
    {
        return 60;
    }
    public function uniqueKey(): ?string
    {
        return 'webhook-delivery:' . $this->deliveryId;
    }
}
