<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Support\Translator;

/** Security notice to every administrator when infrastructure settings change. Mandatory. */
final class PlatformChangedNotification extends Notification
{
    public function __construct(
        public readonly string $subsystem,
        public readonly string $from,
        public readonly string $to,
        public readonly bool $reset,
        public readonly string $by,
        public readonly string $siteUrl,
    ) {}

    public function type(): string
    {
        return 'platform-changed';
    }
    public function via(Recipient $to): array
    {
        return ['database', 'mail'];
    }
    public function mandatory(): bool
    {
        return true;
    }

    private function summary(): string
    {
        return $this->reset
            ? Translator::get('{by} reset the {subsystem} settings (now using {to}).', ['by' => $this->by, 'subsystem' => $this->subsystem, 'to' => $this->to])
            : Translator::get('{by} changed the {subsystem} settings ({from} to {to}).', ['by' => $this->by, 'subsystem' => $this->subsystem, 'from' => $this->from, 'to' => $this->to]);
    }

    public function toDatabase(Recipient $to): array
    {
        return ['title' => Translator::get('Infrastructure settings changed'), 'body' => $this->summary(), 'url' => '/admin/system/platform', 'icon' => 'hard-drives'];
    }

    public function toMail(Recipient $to): MailMessage
    {
        $mail = MailMessage::make(Translator::get('Infrastructure settings changed'))
            ->greeting(Translator::get('Infrastructure settings changed'))
            ->line($this->summary())
            ->line(Translator::get('If you did not expect this, review the settings and the audit log now.'));
        if (str_starts_with($this->siteUrl, 'https://') || str_starts_with($this->siteUrl, 'http://')) {
            $mail->action(Translator::get('Review settings'), rtrim($this->siteUrl, '/') . '/admin/system/platform');
        }

        return $mail;
    }
}
