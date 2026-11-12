<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Events\NotificationFailed;
use Kestello\Cms\Events\NotificationSent;
use Kestello\Cms\Notifications\Channels\DatabaseChannel;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Notifications\Channel;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Support\Translator;

/**
 * $cms->notifications: send notifications, add channels, declare the types
 * users can configure.
 *
 *   $cms->notifications->send($userId, new OrderShipped(42));
 *   $cms->notifications->send([1, 2, 3], new SiteUpdated());
 *   $cms->notifications->send(Recipient::email('guest@example.com'), new FormReceived($id));
 *   $cms->notifications->channel('acme-sms', new SmsChannel());          // plugin channel
 *   $cms->notifications->type(OrderShipped::class, 'Order shipped');     // shows in profile preferences
 *   $cms->notifications->type(StockLow::class, 'Stock running low', capability: 'acme-shop.manage'); // only for people who get it
 *
 * @api
 */
final class Notifier
{
    /** @var array<string, array{channel: Channel, owner: string}> */
    private array $channels = [];

    /** @var array<string, array{class: class-string<Notification>, label: string, description: string, owner: string, capabilities: list<string>}> */
    private array $types = [];

    private ?Closure $queue = null;
    private ?EventDispatcher $events = null;

    public function __construct()
    {
        $this->channels['database'] = ['channel' => new DatabaseChannel(), 'owner' => 'core'];
    }

    /**
     * @internal Kernel wiring.
     * @param Closure(): JobQueue $queue
     */
    public function wire(Closure $queue, ?EventDispatcher $events): void
    {
        $this->queue = $queue;
        $this->events = $events;
    }

    public function channel(string $name, Channel $channel): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid channel name \"{$name}\".");
        }
        $owner = ExecutionContext::current();
        if (isset($this->channels[$name]) && $this->channels[$name]['owner'] !== $owner) {
            throw new InvalidArgumentException("Notification channel \"{$name}\" is already registered.");
        }
        $this->channels[$name] = ['channel' => $channel, 'owner' => $owner];
    }

    /** @return list<string> */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * Declare a notification type users can switch channels for.
     *
     * Name the capability the people you send it to hold, so only they see it in their
     * preferences: send to the roles from $cms->auth->roles->rolesWith('acme-shop.manage') and
     * pass capability: 'acme-shop.manage'. With a list, any one of them is enough. Without one,
     * everyone who signs in sees the type.
     *
     * @param class-string<Notification> $class
     * @param string|list<string>|null $capability who can receive it (any of these), null for everyone
     */
    public function type(string $class, string $label, string $description = '', string|array|null $capability = null): void
    {
        $capabilities = $capability === null ? [] : array_values(array_unique((array) $capability));
        foreach ($capabilities as $name) {
            if (trim($name) === '') {
                throw new InvalidArgumentException("The capability for notification type {$class} must be a capability name, such as \"system.manage\".");
            }
        }
        if (!is_subclass_of($class, Notification::class)) {
            throw new InvalidArgumentException("{$class} must extend " . Notification::class . '.');
        }
        $type = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->type();
        $owner = ExecutionContext::current();
        if ($owner !== 'core') {
            $slug = explode(':', $owner, 2)[1] ?? '';
            if (!str_starts_with($type, $slug . '.')) {
                throw new InvalidArgumentException("{$owner} notification types must start with \"{$slug}.\", got \"{$type}\".");
            }
        }
        $this->types[$type] = ['class' => $class, 'label' => $label, 'description' => $description, 'owner' => $owner, 'capabilities' => $capabilities];
    }

    /** @return array<string, array{class: class-string<Notification>, label: string, description: string, owner: string, capabilities: list<string>}> */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * The types someone can receive, for their preferences: those for everyone, and those
     * whose capability (any one of them) their role holds.
     *
     * @return array<string, array{class: class-string<Notification>, label: string, description: string, owner: string, capabilities: list<string>}>
     */
    public function typesFor(Principal $principal): array
    {
        return array_filter($this->types, static fn(array $t): bool => self::receives($t['capabilities'], $principal));
    }

    /** @param list<string> $capabilities */
    private static function receives(array $capabilities, Principal $principal): bool
    {
        if ($capabilities === []) {
            return true;
        }
        foreach ($capabilities as $capability) {
            if ($principal->can($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send now, or queue when the notification names a queue (and a worker
     * driver is configured).
     *
     * @param int|Recipient|list<int|Recipient> $to
     * @return array<string, string> "recipient|channel" => sent|skipped|failed|queued
     */
    public function send(int|Recipient|array $to, Notification $notification): array
    {
        $recipients = is_array($to) ? $to : [$to];
        $queueName = $notification->queue();
        $userIds = array_values(array_filter($recipients, 'is_int'));
        if ($queueName !== null && $this->queue !== null && $userIds !== [] && count($userIds) === count($recipients)) {
            ($this->queue)()->push(new SendNotificationJob($userIds, $notification::class, self::arguments($notification), ExecutionContext::current()), queue: $queueName);
            return ['queued' => (string) count($userIds)];
        }

        $results = [];
        foreach ($recipients as $recipient) {
            $r = is_int($recipient) ? Recipient::user($recipient) : $recipient;
            if ($r === null) {
                continue;
            }
            foreach ($this->sendTo($r, $notification) as $channel => $status) {
                $results[($r->userId ?? $r->email) . '|' . $channel] = $status;
            }
        }

        return $results;
    }

    /** @return array<string, string> channel => status */
    public function sendTo(Recipient $to, Notification $notification): array
    {
        $disabled = $notification->mandatory() || $to->userId === null ? [] : $this->disabledChannels($to->userId, $notification->type());
        $results = [];
        $send = function () use ($to, $notification, $disabled, &$results): void {
            foreach (array_unique($notification->via($to)) as $name) {
                if (in_array($name, $disabled, true)) {
                    $results[$name] = 'skipped';
                    continue;
                }
                $entry = $this->channels[$name] ?? null;
                if ($entry === null) {
                    $results[$name] = 'failed';
                    $this->events?->dispatch(new NotificationFailed($to->userId, $notification->type(), $name, "Unknown channel \"{$name}\"."));
                    continue;
                }
                try {
                    ExecutionContext::run($entry['owner'], fn() => $entry['channel']->send($to, $notification));
                    $results[$name] = 'sent';
                    $this->events?->dispatch(new NotificationSent($to->userId, $notification->type(), $name));
                } catch (\Throwable $e) {
                    $results[$name] = 'failed';
                    error_log("[kestello] notification {$notification->type()} via {$name} failed: " . $e->getMessage());
                    $this->events?->dispatch(new NotificationFailed($to->userId, $notification->type(), $name, $e->getMessage()));
                }
            }
        };
        $to->locale !== null ? Translator::withLocale($to->locale, $send) : $send();

        return $results;
    }

    // ── inbox and preferences ────────────────────────────────────

    /** @return list<string> channels the user switched off for $type */
    public function disabledChannels(int $userId, string $type): array
    {
        $rows = DatabaseManager::getConnection()->table('notification_preferences')
            ->where('user_id', $userId)->where('type', $type)->where('enabled', false)->get();

        return array_values(array_map(static fn(array $r) => (string) $r['channel'], $rows));
    }

    public function setPreference(int $userId, string $type, string $channel, bool $enabled): void
    {
        $db = DatabaseManager::getConnection();
        $db->table('notification_preferences')->where('user_id', $userId)->where('type', $type)->where('channel', $channel)->delete();
        if (!$enabled) { // only "off" is stored; the default is on
            $db->table('notification_preferences')->insert(['user_id' => $userId, 'type' => $type, 'channel' => $channel, 'enabled' => false]);
        }
    }

    public function unreadCount(int $userId): int
    {
        return DatabaseManager::getConnection()->table('notifications')->where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function markRead(int $userId, ?string $id = null): int
    {
        $q = DatabaseManager::getConnection()->table('notifications')->where('user_id', $userId)->whereNull('read_at');
        if ($id !== null) {
            $q = $q->where('id', $id);
        }

        return $q->update(['read_at' => time()]);
    }

    /** Daily cleanup: read entries older than $readDays, anything older than $maxDays. */
    public function prune(int $readDays = 90, int $maxDays = 365): int
    {
        $db = DatabaseManager::getConnection();
        $n = $db->table('notifications')->whereNotNull('read_at')->where('read_at', '<', time() - $readDays * 86400)->delete();

        return $n + $db->table('notifications')->where('created_at', '<', time() - $maxDays * 86400)->delete();
    }

    /** @return array<string, mixed> */
    private static function arguments(Notification $n): array
    {
        $ref = new \ReflectionClass($n);
        $args = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
            $name = $param->getName();
            if (!$ref->hasProperty($name)) {
                throw new InvalidArgumentException($n::class . " is queued, so constructor argument \${$name} must be a property.");
            }
            $args[$name] = $ref->getProperty($name)->getValue($n);
        }

        return $args;
    }
}
