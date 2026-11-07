<?php

declare(strict_types=1);

namespace Kestello\Contracts\Mail;

/**
 * Delivers email. Core ships SMTP and PHP mail() delivery; plugins can bind
 * another implementation (SES, Postmark, a dev mail catcher…):
 *
 *     $container->singleton(Transport::class, fn() => new MyTransport());
 *
 * and declare it in their manifest: "replaces": ["Kestello\\Contracts\\Mail\\Transport"].
 *
 * @api
 */
interface Transport
{
    /** Short identifier shown in the admin, e.g. "ses". */
    public function name(): string;

    /** Return true when the message was accepted for delivery. */
    public function send(Message $message): bool;
}
