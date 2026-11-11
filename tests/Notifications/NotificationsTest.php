<?php

declare(strict_types=1);

namespace Kestello\Tests\Notifications;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Events\NotificationFailed;
use Kestello\Cms\Events\NotificationSent;
use Kestello\Cms\Health\HealthModule;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\NotificationsModule;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Cms\Webhooks\WebhooksModule;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Notifications\Channel;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Tests\Auth\AuthTestCase;

final class OrderShipped extends Notification
{
    public function __construct(public readonly int $orderId, public readonly string $note = '') {}
    public function type(): string
    {
        return 'order-shipped';
    }
    public function via(Recipient $to): array
    {
        return ['database', 'mail'];
    }
    public function toDatabase(Recipient $to): array
    {
        return ['title' => "Order #{$this->orderId} shipped", 'url' => "/orders/{$this->orderId}"];
    }
    public function toMail(Recipient $to): MailMessage
    {
        return MailMessage::make("Order #{$this->orderId} shipped")->greeting('Hi ' . $to->name)->line('<b>' . $this->note . '</b>')->action('Track it', 'https://shop.test/t/1');
    }
}

final class SecurityAlert extends Notification
{
    public function via(Recipient $to): array
    {
        return ['mail', 'database', 'pager'];
    }
    public function toDatabase(Recipient $to): array
    {
        return ['title' => 'New sign-in'];
    }
    public function toMail(Recipient $to): MailMessage
    {
        return MailMessage::make('New sign-in');
    }
    public function mandatory(): bool
    {
        return true;
    }
}

final class QueuedDigest extends Notification
{
    public function __construct(public readonly string $period) {}
    public function toDatabase(Recipient $to): array
    {
        return ['title' => "Your {$this->period} digest"];
    }
    public function queue(): ?string
    {
        return 'mail';
    }
}

final class NotificationsTest extends AuthTestCase
{
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms->notifications->wire(fn() => $this->cms->queue, $this->cms->events);
        (new NotificationsModule())->boot($this->cms);
        foreach ([NotificationSent::class, NotificationFailed::class] as $event) {
            $this->cms->events->on($event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    public function testMailCarriesTheSiteNameNotTheAppName(): void
    {
        $this->cms->config->set('app.name', 'Config Name');
        \Kestello\Framework\Settings\Settings::getInstance()->set('site_name', 'Bakery Ana');
        $ana = $this->createUser();
        $this->cms->notifications->send((int) $ana['id'], new OrderShipped(42));

        $this->assertStringEndsWith("\n\nBakery Ana", $this->mail[0]['text']);
        $this->assertStringContainsString('>Bakery Ana</td>', $this->mail[0]['html']);
        $this->assertStringNotContainsString('Config Name', $this->mail[0]['html'] . $this->mail[0]['text']);

        \Kestello\Framework\Settings\Settings::getInstance()->set('email_footer', "Main street 1\nNovi Sad");
        $this->cms->notifications->send((int) $ana['id'], new OrderShipped(43));
        $this->assertStringEndsWith("\n\nBakery Ana\nMain street 1\nNovi Sad", $this->mail[1]['text'], 'Settings → Emails footer text');
        $this->assertStringContainsString('>Bakery Ana<br>Main street 1<br>Novi Sad</td>', $this->mail[1]['html']);
        $this->assertStringNotContainsString('<img', $this->mail[1]['html'], 'no logo chosen and no publisher logo: none drawn');
    }

    public function testChannelsInboxAndPreferences(): void
    {
        $ana = $this->createUser();
        $n = $this->cms->notifications;

        $results = $n->send((int) $ana['id'], new OrderShipped(42, 'Fragile & <glass>'));
        $this->assertSame(["{$ana['id']}|database" => 'sent', "{$ana['id']}|mail" => 'sent'], $results);
        $this->assertCount(1, $this->mail);
        $this->assertSame('Order #42 shipped', $this->mail[0]['subject']);
        $this->assertContains('&lt;b&gt;Fragile &amp; &lt;glass&gt;&lt;/b&gt;', $this->mail[0]['html'], 'mail content is escaped');
        $this->assertContains('href="https://shop.test/t/1"', $this->mail[0]['html']);
        $this->assertSame("Hi ana\n\n<b>Fragile & <glass></b>\n\nTrack it\nhttps://shop.test/t/1\n\nKestello", $this->mail[0]['text'], 'a plain-text part goes with the HTML, unescaped');
        $this->assertSame(1, $n->unreadCount((int) $ana['id']));

        $n->type(OrderShipped::class, 'Order shipped');
        $n->setPreference((int) $ana['id'], 'order-shipped', 'mail', false);
        $this->assertSame('skipped', $n->send((int) $ana['id'], new OrderShipped(43))["{$ana['id']}|mail"]);
        $this->assertCount(1, $this->mail, 'mail switched off');

        $n->setPreference((int) $ana['id'], 'security-alert', 'mail', false);
        $alert = $n->send((int) $ana['id'], new SecurityAlert());
        $this->assertSame('sent', $alert["{$ana['id']}|mail"], 'mandatory notifications ignore preferences');
        $this->assertSame('failed', $alert["{$ana['id']}|pager"], 'unknown channel fails without stopping the others');
        $this->assertInstanceOf(NotificationFailed::class, end($this->events));

        $guest = $n->send(Recipient::email('guest@example.test', 'Guest'), new OrderShipped(44));
        $this->assertSame('sent', $guest['guest@example.test|mail']);
        $this->assertSame(3, $n->unreadCount((int) $ana['id']), 'guests have no inbox');
    }

    public function testInboxApi(): void
    {
        $ana = $this->createUser();
        $bo = $this->createUser(['username' => 'bo', 'email' => 'bo@example.test']);
        foreach ([1, 2, 3] as $i) {
            $this->cms->notifications->send((int) $ana['id'], new OrderShipped($i));
        }
        $this->cms->notifications->send((int) $bo['id'], new OrderShipped(99));
        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);

        $page = $this->json($this->api('GET', '/me/notifications?limit=2'));
        $this->assertSame(['Order #3 shipped', 'Order #2 shipped'], array_column(array_column($page['data'], 'data'), 'title'), 'newest first, only mine');
        $this->assertSame(3, $page['meta']['unread']);
        $rest = $this->json($this->api('GET', '/me/notifications?limit=2&before=' . $page['meta']['next_before']));
        $this->assertSame(['Order #1 shipped'], array_column(array_column($rest['data'], 'data'), 'title'));
        $this->assertNull($rest['meta']['next_before']);

        $bosId = (string) $this->db->table('notifications')->where('user_id', $bo['id'])->first()['id'];
        $this->assertSame(0, $this->json($this->api('POST', '/me/notifications/read', ['id' => $bosId]))['data']['marked'], "can't touch someone else's");
        $one = $this->json($this->api('POST', '/me/notifications/read', ['id' => $page['data'][0]['id']]));
        $this->assertSame(['marked' => 1, 'unread' => 2], $one['data']);
        $this->assertSame(2, $this->json($this->api('POST', '/me/notifications/read'))['data']['marked']);
        $this->assertSame([], $this->json($this->api('GET', '/me/notifications?unread=1'))['data']);

        $this->cms->notifications->type(OrderShipped::class, 'Order shipped', 'When an order leaves the warehouse');
        $prefs = $this->json($this->api('GET', '/me/notification-preferences'))['data'];
        $this->assertSame('order-shipped', $prefs[0]['type']);
        $this->assertSame([['channel' => 'database', 'enabled' => true], ['channel' => 'mail', 'enabled' => true]], $prefs[0]['channels']);
        $this->assertSame(204, $this->api('PUT', '/me/notification-preferences', ['preferences' => [['type' => 'order-shipped', 'channel' => 'mail', 'enabled' => false]]])->statusCode());
        $this->assertSame(['mail'], $this->cms->notifications->disabledChannels((int) $ana['id'], 'order-shipped'));
        $this->assertSame(422, $this->api('PUT', '/me/notification-preferences', ['preferences' => [['type' => 'nope', 'channel' => 'mail', 'enabled' => false]]])->statusCode());
    }

    public function testPreferencesOnlyListTypesYouCanReceive(): void
    {
        $this->createUser();
        $bo = $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'role' => 'user']);
        $n = $this->cms->notifications;
        $n->type(OrderShipped::class, 'Order shipped');
        $n->type(QueuedDigest::class, 'Digest', 'For people who run the site', 'system.manage');
        $n->type(SecurityAlert::class, 'Security alerts', '', ['users.manage', 'security.manage']);
        $this->assertSame([], $n->types()['order-shipped']['capabilities'], 'no capability: everyone can receive it');
        $this->assertSame(['users.manage', 'security.manage'], $n->types()['security-alert']['capabilities']);
        $this->assertThrows(\InvalidArgumentException::class, fn() => $n->type(OrderShipped::class, 'Order shipped', '', ['']), 'an empty capability is a mistake');

        $member = new Principal((int) $bo['id'], [], 'session', ['read', 'profile.manage']);
        $this->assertSame(['order-shipped'], array_keys($n->typesFor($member)));
        $security = new Principal((int) $bo['id'], [], 'session', ['security.manage']);
        $this->assertSame(['order-shipped', 'security-alert'], array_keys($n->typesFor($security)), 'any one of the capabilities is enough');
        $admin = new Principal(1, [], 'session', ['*']);
        $this->assertSame(['order-shipped', 'queued-digest', 'security-alert'], array_keys($n->typesFor($admin)));

        $this->api('POST', '/auth/session', ['login' => 'bo', 'password' => self::PASSWORD]);
        $this->assertSame(['order-shipped'], array_column($this->json($this->api('GET', '/me/notification-preferences'))['data'], 'type'), 'a member sees only what can reach them');
        $refused = $this->api('PUT', '/me/notification-preferences', ['preferences' => [['type' => 'queued-digest', 'channel' => 'mail', 'enabled' => false]]]);
        $this->assertSame(422, $refused->statusCode(), 'a type you cannot receive is refused like an unknown one');
        $this->assertSame([], $n->disabledChannels((int) $bo['id'], 'queued-digest'), 'nothing was stored');

        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);
        $this->assertSame(['order-shipped', 'queued-digest', 'security-alert'], array_column($this->json($this->api('GET', '/me/notification-preferences'))['data'], 'type'));
        $this->assertSame(204, $this->api('PUT', '/me/notification-preferences', ['preferences' => [['type' => 'queued-digest', 'channel' => 'mail', 'enabled' => false]]])->statusCode());
    }

    public function testCoreTypesReachOnlyThePeopleTheyAreSentTo(): void
    {
        Model::resetRegistry();
        try {
            $content = new ContentModule();
            $content->register($this->cms->container);
            $content->boot($this->cms);
            (new HealthModule())->boot($this->cms);
            (new WebhooksModule())->boot($this->cms);
            $roles = $this->cms->auth->roles;
            $as = fn(string $role): array => array_keys($this->cms->notifications->typesFor(new Principal(1, [], 'session', $roles->capabilitiesFor($role))));
            $all = ['content-review', 'content-comment', 'site-health', 'webhook-disabled'];
            $this->assertSame($all, array_keys($this->cms->notifications->types()), 'the core types');

            $this->assertSame([], $as('user'), 'a member gets none of them');
            $this->assertSame(['content-review', 'content-comment'], $as('contributor'), 'people who use the content editor get reviews and comments');
            $this->assertSame(['content-review', 'content-comment'], $as('editor'));
            $this->assertSame($all, $as('admin'), 'administrators get Site Health and webhook notices too');
        } finally {
            Model::resetRegistry();
        }
    }

    public function testQueuedPluginChannelsAndPruning(): void
    {
        $ana = $this->createUser();
        $queue = new ArrayQueue();
        $this->cms->queue->setBackend(static fn() => $queue);
        $this->cms->queue->setContainer($this->cms->container, $this->cms->events);

        $this->assertSame(['queued' => '1'], $this->cms->notifications->send([(int) $ana['id']], new QueuedDigest('weekly')));
        $this->assertSame(0, $this->cms->notifications->unreadCount((int) $ana['id']));
        $this->assertCount(1, $queue->jobs);
        $reserved = $queue->reserve(['mail'], 60);
        $this->assertSame('done', $this->cms->queue->process($reserved));
        $this->assertSame(1, $this->cms->notifications->unreadCount((int) $ana['id']), 'sent by the worker');

        $sent = [];
        $sms = new class ($sent) implements Channel {
            public function __construct(private array &$sent) {}
            public function send(Recipient $to, Notification $n): void
            {
                $this->sent[] = [ExecutionContext::current(), $to->route('acme-sms')];
            }
        };
        ExecutionContext::run('plugin:acme', fn() => $this->cms->notifications->channel('acme-sms', $sms));
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:other', fn() => $this->cms->notifications->channel('acme-sms', $sms)));
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $this->cms->notifications->type(OrderShipped::class, 'x')), 'plugin types are prefixed');
        $smsNote = new class extends Notification {
            public function via(Recipient $to): array
            {
                return ['acme-sms'];
            }
        };
        $this->cms->notifications->send(new Recipient(null, null, 'Pat', null, ['acme-sms' => '+381600000000']), $smsNote);
        $this->assertSame([['plugin:acme', '+381600000000']], $sent, 'channel runs as its owner');

        $this->db->table('notifications')->update(['created_at' => time() - 400 * 86400]);
        $this->assertSame(1, $this->cms->notifications->prune());
    }
}
