<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Support\Translator;

/**
 * Someone made their own account: by email sign-up or by signing in with a
 * provider. Sent to the people whose role may manage people, who can turn it
 * off in their notification preferences. Accounts made in the admin or with
 * user:create are not announced: whoever made them knows.
 */
final class NewSignUpNotification extends Notification
{
    /** @param string $method "email", or the provider's label such as "GitHub" */
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $email,
        public readonly string $method,
        public readonly string $siteUrl,
    ) {}

    public function type(): string
    {
        return 'new-sign-up';
    }

    public function via(Recipient $to): array
    {
        return ['database', 'mail'];
    }

    public function queue(): ?string
    {
        return 'mail';
    }

    private function title(): string
    {
        return Translator::get('{name} signed up', ['name' => $this->username]);
    }

    private function how(): string
    {
        return $this->method === 'email'
            ? Translator::get('{email} made an account with the sign-up form.', ['email' => $this->email])
            : Translator::get('{email} made an account by signing in with {provider}.', ['email' => $this->email, 'provider' => $this->method]);
    }

    /** @return array{title: string, body: string, url: string, icon: string} */
    public function toDatabase(Recipient $to): array
    {
        return ['title' => $this->title(), 'body' => $this->how(), 'url' => '/admin/users/' . $this->userId, 'icon' => 'user'];
    }

    public function toMail(Recipient $to): MailMessage
    {
        $mail = MailMessage::make($this->title())->heading($this->title())
            ->paragraph($this->how())
            ->paragraph(Translator::get('Open their profile to change their role, or delete the account if you do not know them.'));
        if (str_starts_with($this->siteUrl, 'https://') || str_starts_with($this->siteUrl, 'http://')) {
            $mail->button(Translator::get('Open their profile'), rtrim($this->siteUrl, '/') . '/admin/users/' . $this->userId);
        }

        return $mail->note(Translator::get('You get this because you can manage people. Turn it off under Your profile → Notifications.'));
    }
}
