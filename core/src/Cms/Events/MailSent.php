<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * The mail server accepted an email.
 *
 * @api
 */
final readonly class MailSent
{
    /**
     * @param string $id The email, such as auth.password_reset, or notification.<type>.
     * @param string $to The address it went to (several are separated by commas).
     * @param string $subject Its subject.
     */
    public function __construct(
        public string $id,
        public string $to,
        public string $subject,
    ) {}
}
