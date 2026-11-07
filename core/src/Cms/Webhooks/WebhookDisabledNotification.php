<?php

declare(strict_types=1);

namespace Kestello\Cms\Webhooks;

use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Support\Translator;

/** A webhook kept failing and was turned off. Sent to administrators. */
final class WebhookDisabledNotification extends Notification
{
    public function __construct(
        public readonly int $webhookId,
        public readonly string $name,
        public readonly int $failures,
        public readonly string $siteUrl,
    ) {}

    public function type(): string
    {
        return 'webhook-disabled';
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
        return Translator::get('The webhook "{name}" was turned off', ['name' => $this->name]);
    }

    public function toDatabase(Recipient $to): array
    {
        return [
            'title' => $this->title(),
            'body' => Translator::get('{count} deliveries in a row failed. Check the receiving service, then turn the webhook on again.', ['count' => $this->failures]),
            'url' => '/admin/system/webhooks/' . $this->webhookId,
            'icon' => 'alert',
        ];
    }

    public function toMail(Recipient $to): MailMessage
    {
        $mail = MailMessage::make($this->title())->greeting($this->title())
            ->line(Translator::get('{count} deliveries in a row failed, each after about 21 hours of retries, so Kestello stopped sending to it.', ['count' => $this->failures]))
            ->line(Translator::get('Check that the receiving service is running and answers with a 2xx status, then turn the webhook on again. Its delivery log shows what each attempt got back.'));
        if (str_starts_with($this->siteUrl, 'https://') || str_starts_with($this->siteUrl, 'http://')) {
            $mail->action(Translator::get('Open the webhook'), rtrim($this->siteUrl, '/') . '/admin/system/webhooks/' . $this->webhookId);
        }

        return $mail;
    }
}
