<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Support\Translator;

/** New critical Site Health problems (sent once per problem by the health.check task). */
final class HealthAlertNotification extends Notification
{
    /** @param list<array{label: string, message: string}> $problems */
    public function __construct(public readonly array $problems, public readonly string $siteUrl) {}

    public function type(): string
    {
        return 'site-health';
    }
    public function via(Recipient $to): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(Recipient $to): array
    {
        return [
            'title' => Translator::get('{count, plural, one {# critical Site Health problem} other {# critical Site Health problems}}', ['count' => count($this->problems)]),
            'body' => implode(' ', array_map(static fn(array $p) => $p['label'] . ': ' . $p['message'], $this->problems)),
            'url' => '/admin/system/health',
            'icon' => 'alert',
        ];
    }

    public function toMail(Recipient $to): MailMessage
    {
        $mail = MailMessage::make(Translator::get('Your site needs attention'))->greeting(Translator::get('Your site needs attention'));
        foreach ($this->problems as $p) {
            $mail->line($p['label'] . ': ' . $p['message']);
        }
        if (str_starts_with($this->siteUrl, 'https://') || str_starts_with($this->siteUrl, 'http://')) {
            $mail->action(Translator::get('Open Site Health'), rtrim($this->siteUrl, '/') . '/admin/system/health');
        }

        return $mail;
    }
}
