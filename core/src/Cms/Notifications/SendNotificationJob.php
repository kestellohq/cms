<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Core\ExecutionContext;

/** Sends a queued notification from the worker (core job). @internal */
final class SendNotificationJob implements Job
{
    /**
     * @param list<int>            $userIds
     * @param array<string, mixed> $args
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $notification,
        public readonly array $args,
        public readonly string $owner,
    ) {}

    public function handle(Cms $cms): void
    {
        if (!is_subclass_of($this->notification, Notification::class)) {
            throw new \RuntimeException("{$this->notification} is not a notification.");
        }
        $owner = preg_match('/^(core|plugin:[a-z0-9-]+|theme:[a-z0-9-]+)$/', $this->owner) === 1 ? $this->owner : 'core';
        ExecutionContext::run($owner, function () use ($cms): void {
            $notification = new ($this->notification)(...$this->args);
            foreach ($this->userIds as $id) {
                $to = Recipient::user((int) $id);
                if ($to !== null) {
                    $cms->notifications->sendTo($to, $notification);
                }
            }
        });
    }

    public function retries(): int
    {
        return 3;
    }
}
