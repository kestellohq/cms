<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications\Channels;

use Closure;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Contracts\Notifications\Channel;

final class MailChannel implements Channel
{
    /** @param Closure(string $to, string $subject, string $html): bool $mailer */
    public function __construct(private readonly Closure $mailer, private readonly string $siteName = 'Kestello') {}

    public function send(Recipient $to, Notification $notification): void
    {
        $message = $notification->toMail($to);
        $address = $to->route('mail');
        if ($message === null || $address === null || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        if (!($this->mailer)($address, $message->subject, $message->render($this->siteName))) {
            throw new \RuntimeException('The mail server did not accept the message.');
        }
    }
}
