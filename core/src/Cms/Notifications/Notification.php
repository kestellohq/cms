<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

/**
 * Something a user should hear about.
 *
 *   final class OrderShipped extends Notification {
 *       public function __construct(public readonly int $orderId, public readonly string $tracking) {}
 *       public function type(): string { return 'acme-shop.order-shipped'; }
 *       public function via(Recipient $to): array { return ['database', 'mail']; }
 *       public function toDatabase(Recipient $to): array {
 *           return ['title' => __('Order #{id} shipped', ['id' => $this->orderId]), 'url' => "/orders/{$this->orderId}"];
 *       }
 *       public function toMail(Recipient $to): MailMessage {
 *           return MailMessage::make('Your order is on its way')->line('Tracking: ' . $this->tracking)->action('Track it', $url);
 *       }
 *       public function queue(): ?string { return 'mail'; }   // send from the worker
 *   }
 *   $cms->notifications->send($userId, new OrderShipped(42, 'XY123'));
 *
 * Queued notifications are rebuilt from their constructor arguments, which
 * must be exposed as properties and hold plain values (like jobs).
 *
 * @api
 */
abstract class Notification
{
    /** Stable id used for preferences and the inbox ("acme-shop.order-shipped"). */
    public function type(): string
    {
        $base = substr(static::class, (int) strrpos(static::class, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $base));
    }

    /** @return list<string> channel names */
    public function via(Recipient $to): array
    {
        return ['database'];
    }

    /** Inbox entry. Conventional keys: title, body, url, icon. @return array<string, mixed> */
    public function toDatabase(Recipient $to): array
    {
        return [];
    }

    public function toMail(Recipient $to): ?MailMessage
    {
        return null;
    }

    /** Security notices and receipts: user preferences can't turn them off. */
    public function mandatory(): bool
    {
        return false;
    }

    /** Queue name to send from the worker, or null to send right away. */
    public function queue(): ?string
    {
        return null;
    }
}
