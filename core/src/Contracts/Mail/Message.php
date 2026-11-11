<?php

declare(strict_types=1);

namespace Kestello\Contracts\Mail;

/**
 * An outgoing email, as handed to a Transport.
 *
 * @api
 */
final readonly class Message
{
    /**
     * @param list<string>          $to
     * @param array<string, string> $headers Includes From, MIME-Version, Content-Type (of $html).
     * @param string                $text    The plain-text version of the HTML, sent with it as
     *        multipart/alternative; empty when the message has the HTML part only.
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $html,
        public array $headers,
        public string $text = '',
    ) {}
}
