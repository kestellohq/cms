<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

use Kestello\Contracts\Mail\Message;
use Kestello\Contracts\Mail\Transport;

/**
 * Mail on a public demo: accepted and dropped. Anyone can sign in, so
 * anything that sends email (forms, notifications, invitations) could
 * otherwise be used to send it to anyone.
 */
final class DemoTransport implements Transport
{
    public function name(): string
    {
        return 'demo';
    }

    public function send(Message $message): bool
    {
        return true;
    }
}
