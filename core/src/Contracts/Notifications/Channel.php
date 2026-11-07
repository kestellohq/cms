<?php

declare(strict_types=1);

namespace Kestello\Contracts\Notifications;

use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;

/**
 * A delivery channel ("mail", "database", plugin "slack", "sms"…).
 * Throw to report a failure; the notifier records it and moves on to the
 * next channel.
 *
 * @api
 */
interface Channel
{
    public function send(Recipient $to, Notification $notification): void;
}
