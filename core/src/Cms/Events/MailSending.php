<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

use Kestello\Cms\Notifications\MailMessage;

/**
 * An email is about to go out. A listener can change $message (add a
 * paragraph, a note) or stop it with cancel(): the email isn't sent, and
 * the sender is told it was stopped, not that it failed.
 *
 *   $cms->events->on(MailSending::class, function (MailSending $e) {
 *       if ($e->id === 'forms.submission' && str_ends_with($e->to, '@example.test')) {
 *           $e->cancel('Test addresses get no form emails.');
 *       }
 *   });
 *
 * $id is the named email ("auth.password_reset"), or "notification.<type>"
 * for a notification.
 *
 * @api
 */
final class MailSending
{
    private ?string $reason = null;

    /**
     * @param string $id The email, such as auth.password_reset, or notification.<type>.
     * @param string $to The address it goes to (several are separated by commas).
     * @param MailMessage $message The message as built; a listener may change it or replace it.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public MailMessage $message,
    ) {}

    public function cancel(string $reason): void
    {
        $this->reason ??= trim($reason) !== '' ? trim($reason) : 'A plugin stopped this email.';
    }

    public function cancelled(): bool
    {
        return $this->reason !== null;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
