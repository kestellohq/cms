<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A notification was delivered on one channel.
 *
 * @api
 */
final readonly class NotificationSent
{
    /**
     * @param ?int $userId The recipient, or null for an address that is not a user.
     * @param string $type The notification type.
     * @param string $channel `database`, `mail`, or a channel a plugin added.
     */
    public function __construct(
        public ?int $userId,
        public string $type,
        public string $channel,
    ) {}
}
