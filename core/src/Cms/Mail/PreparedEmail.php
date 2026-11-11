<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

/** An email ready for the mail server: both parts rendered, nothing left to decide. */
final class PreparedEmail
{
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
    ) {}
}
