<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Notifications\Channels\MailChannel;
use Kestello\Framework\Mail\Mailer;

/** Core module: the mail channel and the queued-send job. */
final class NotificationsModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $container = $cms->container;
        $mailer = static function (string $to, string $subject, string $html, string $text) use ($container): bool {
            /** @var Closure(string, string, string, string): bool $send */
            $send = $container->has('auth.mail') ? $container->make('auth.mail') : static fn(string $t, string $s, string $h, string $x): bool => (new Mailer())->send($t, $s, $h, [], $x);

            return $send($to, $subject, $html, $text);
        };
        $cms->notifications->channel('mail', new MailChannel($mailer, (string) $cms->config->get('app.name', 'Kestello')));
        $cms->queue->job(SendNotificationJob::class);
    }
}
