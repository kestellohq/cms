<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications\Channels;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Mail\EmailRegistry;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Contracts\Notifications\Channel;

final class MailChannel implements Channel
{
    /**
     * @param Closure(string $to, string $subject, string $html, string $text): bool $mailer
     * @param MailBrand|string|null $brand null: the site's name and look at the time of sending
     */
    public function __construct(private readonly Closure $mailer, private readonly MailBrand|string|null $brand = null) {}

    public function send(Recipient $to, Notification $notification): void
    {
        $message = $notification->toMail($to);
        $address = $to->route('mail');
        if ($message === null || $address === null || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $emails = Cms::instance()->emails ?? new EmailRegistry();
        $email = $emails->prepareMessage('notification.' . $notification->type(), $address, $message, $this->brand);
        if ($email !== null && !$emails->deliver($email, $this->mailer)) {
            throw new \RuntimeException('The mail server did not accept the message.');
        }
    }
}
