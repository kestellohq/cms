<?php

declare(strict_types=1);

namespace Kestello\Framework\Events;

use Attribute;

/**
 * Run this listener class in the background instead of during dispatch:
 *
 *   #[Queued(queue: 'mail')]
 *   final class SendWelcomeMail {
 *       public function __construct(private Mailer $mail) {}         // services come in through the constructor
 *       public function handle(UserRegistered $e): void { ... }      // handle() receives only the event
 *   }
 *
 * The event is stored as JSON (its constructor arguments, read from
 * same-named properties), so it must hold plain data, not objects. Events
 * that can't be stored run the listener inline and log why.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Queued
{
    public function __construct(public string $queue = 'default', public int $delay = 0) {}
}
