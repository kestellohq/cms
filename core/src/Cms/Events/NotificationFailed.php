<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A notification could not be delivered on one channel. Other channels are
 * tried anyway.
 *
 * @api
 */
final readonly class NotificationFailed
{
    /**
     * @param ?int $userId The recipient, or null for an address that is not a user.
     * @param string $type The notification type, such as `core.health-alert`.
     * @param string $channel `database`, `mail`, or a channel a plugin added.
     * @param string $error What went wrong.
     */
    public function __construct(
        public ?int $userId,
        public string $type,
        public string $channel,
        public string $error,
    ) {}
}
