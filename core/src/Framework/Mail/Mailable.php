<?php

declare(strict_types=1);

namespace Kestello\Framework\Mail;

/**
 * Base class for composing emails.
 *
 *   class WelcomeEmail extends Mailable {
 *       protected string $subject = 'Welcome!';
 *       public function __construct(private readonly array $user) {
 *           $this->template = __DIR__ . '/../../emails/welcome.html';
 *       }
 *       public function data(): array { return ['user' => $this->user]; }
 *   }
 *
 * Templates use MailTemplate syntax ({{ key }} escaped, {!! key !!} raw).
 * Send: (new WelcomeEmail($user))->send('user@example.com');
 */
abstract class Mailable
{
    protected string $subject = '';

    /** Absolute path to an .html template file. */
    protected string $template = '';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }

    /**
     * Build the HTML body. Override for custom building.
     */
    public function build(): string
    {
        return $this->template === '' ? '' : MailTemplate::renderFile($this->template, $this->data());
    }

    /**
     * @param string|array<string> $to
     */
    public function send(string|array $to): bool
    {
        return (new Mailer())->send($to, $this->subject, $this->build());
    }

    public function getSubject(): string
    {
        return $this->subject;
    }
}
