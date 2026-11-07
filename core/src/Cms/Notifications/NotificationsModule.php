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
        $mailer = static function (string $to, string $subject, string $html) use ($container): bool {
            /** @var Closure(string, string, string): bool $send */
            $send = $container->has('auth.mail') ? $container->make('auth.mail') : static fn(string $t, string $s, string $h): bool => (new Mailer())->send($t, $s, $h);

            return $send($to, $subject, $html);
        };
        $cms->notifications->channel('mail', new MailChannel($mailer, (string) $cms->config->get('app.name', 'Kestello')));
        $cms->queue->job(SendNotificationJob::class);
    }
}
