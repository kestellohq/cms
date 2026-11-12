<?php

declare(strict_types=1);

namespace Kestello\Tests\Webhooks;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Queue\Worker;
use Kestello\Cms\Webhooks\Signature;
use Kestello\Cms\Webhooks\Webhooks;
use Kestello\Cms\Webhooks\WebhooksModule;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Support\Clock;
use Kestello\Tests\Auth\AuthTestCase;
use LogicException;

/** Outgoing webhooks: signed deliveries, retries, turning off, rotation and the API. */
final class WebhooksTest extends AuthTestCase
{
    private const string URL = 'https://hooks.example.com/kestello';

    private FakeTransport $fake;
    private int $status = 200;

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        Clock::freeze('2027-01-04 10:00:00');
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        (new WebhooksModule())->boot($this->cms);

        $this->fake = new FakeTransport(['POST ' . self::URL => fn(PendingRequest $r) => ['status' => $this->status, 'body' => '{"ok":true,"token":"abcd1234secretvalue"}']]);
        $http = new HttpClient($this->fake, static fn(string $host): array => match ($host) {
            'hooks.example.com' => ['93.184.216.34'],
            'internal.example.com' => ['10.0.0.5'],
            default => [],
        });
        $this->cms->webhooks->useHttp(static fn() => $http);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    public function testDeliversSignedEvents(): void
    {
        $this->assertContains('entry.published', array_column($this->json($this->api('GET', '/webhooks/events'))['data'], 'name'));

        $r = $this->api('POST', '/webhooks', ['name' => 'Plain', 'url' => 'http://hooks.example.com/x', 'events' => ['entry.created']]);
        $this->assertSame(422, $r->statusCode());
        $this->assertStringContainsString('Use an https:', $r->content());
        $r = $this->api('POST', '/webhooks', ['name' => 'Inside', 'url' => 'https://internal.example.com/x', 'events' => ['entry.created']]);
        $this->assertSame(422, $r->statusCode(), 'private addresses are refused');
        $this->assertSame(
            ['This address is not allowed: webhooks only go to public servers on ports 80 and 443, and internal.example.com is not one.'],
            $this->json($r)['errors']['url'],
            'it names the host once, in plain words',
        );
        $this->assertSame(422, $this->api('POST', '/webhooks', ['name' => 'Odd', 'url' => self::URL, 'events' => ['nope.never']])->statusCode());

        $made = $this->hook(['entry.created', 'entry.published']);
        $this->assertMatchesRegularExpression('/^whsec_/', $made['secret']);
        $this->assertArrayNotHasKey('secret', $this->json($this->api('GET', "/webhooks/{$made['id']}"))['data'], 'shown once');

        $post = $this->json($this->api('POST', '/content/post/entries', ['title' => 'Hello', 'status' => 'published']))['data'];
        $this->assertCount(2, $this->fake->sent, 'created and published');
        $req = $this->fake->sent[0];
        $body = json_decode((string) $req->body, true);
        $this->assertSame(['entry.created', $post['id'], 'Hello'], [$body['type'], $body['data']['id'], $body['data']['title']]);
        $this->assertMatchesRegularExpression('/^evt_/', $body['id']);
        $headers = array_change_key_case($req->headers);
        $this->assertSame('entry.created', $headers['kestello-event']);
        $this->assertTrue(Signature::verify($headers['kestello-signature'], (string) $req->body, $made['secret'], now: Clock::timestamp()));
        $this->assertFalse(Signature::verify($headers['kestello-signature'], (string) $req->body . ' ', $made['secret'], now: Clock::timestamp()), 'a changed body fails');
        $this->assertFalse(Signature::verify($headers['kestello-signature'], (string) $req->body, $made['secret'], now: Clock::timestamp() + 600), 'an old message fails');

        $log = $this->json($this->api('GET', "/webhooks/{$made['id']}/deliveries"))['data'];
        $this->assertSame(['succeeded', 'succeeded'], array_column($log, 'status'));
        $this->assertStringNotContainsString('abcd1234secretvalue', (string) $log[0]['response_body'], 'credentials in the answer are hidden');
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'webhook.created')->count());
    }

    public function testTheDefaultWorkerDeliversThem(): void
    {
        $backend = new ArrayQueue();
        $this->cms->queue->setBackend(static fn() => $backend);
        $this->hook(['entry.created']);
        $this->api('POST', '/content/post/entries', ['title' => 'Queued', 'status' => 'draft']);
        $this->assertCount(0, $this->fake->sent, 'queued, not sent during the request');

        // the same queues the cron task and a plain queue:work read
        (new Worker($this->cms->queue, new ArrayStore(), sleep: static fn() => null))->run(JobQueue::QUEUES, stopWhenEmpty: true, maxJobs: 10);
        $this->assertCount(1, $this->fake->sent, 'a queue no worker reads would leave it waiting for ever');
    }

    public function testADeliveryWhoseJobWasLostIsQueuedAgain(): void
    {
        $backend = new ArrayQueue();
        $this->cms->queue->setBackend(static fn() => $backend);
        $this->hook(['entry.created']);
        $this->api('POST', '/content/post/entries', ['title' => 'Stranded', 'status' => 'draft']);
        // as on a site that queued it on the "webhooks" queue, which no worker reads since 0.1.0
        $backend->clear();
        $this->assertSame('pending', $this->db->table('webhook_deliveries')->value('status'));

        $this->assertSame(0, $this->cms->webhooks->retryDue(), 'a pending delivery is left to its job for an hour');
        Clock::travel(3601);
        $this->assertSame(1, $this->cms->webhooks->retryDue());
        $this->assertSame(0, $this->cms->webhooks->retryDue(), 'once an hour, not every minute');
        (new Worker($this->cms->queue, new ArrayStore(), sleep: static fn() => null))->run(JobQueue::QUEUES, stopWhenEmpty: true, maxJobs: 10);
        $this->assertCount(1, $this->fake->sent);
        $this->assertSame('succeeded', $this->db->table('webhook_deliveries')->value('status'));
    }

    public function testRetriesAndTurnsOffAfterRepeatedFailures(): void
    {
        $made = $this->hook(['entry.created']);
        $this->status = 503;
        $this->api('POST', '/content/post/entries', ['title' => 'One']);
        $delivery = $this->db->table('webhook_deliveries')->first();
        $this->assertSame(['retrying', 1, 503], [$delivery['status'], (int) $delivery['attempts'], (int) $delivery['response_status']]);
        $this->assertSame('2027-01-04 10:01:00', $delivery['next_attempt_at'], 'a minute later');

        $this->assertSame(0, $this->cms->webhooks->retryDue(), 'not due yet');
        Clock::travel(61);
        $this->status = 200;
        $this->assertSame(1, $this->cms->webhooks->retryDue());
        $this->assertSame(['succeeded', 2], [$this->db->table('webhook_deliveries')->value('status'), (int) $this->db->table('webhook_deliveries')->value('attempts')]);

        // Give up on one delivery after the last retry …
        $this->status = 500;
        $this->db->table('webhooks')->where('id', $made['id'])->update(['failure_streak' => Webhooks::DISABLE_AFTER - 2]);
        $this->api('POST', '/content/post/entries', ['title' => 'Two']);
        $id = (int) $this->db->table('webhook_deliveries')->orderByDesc('id')->value('id');
        foreach (Webhooks::BACKOFF as $seconds) {
            Clock::travel($seconds);
            $this->cms->webhooks->retryDue();
        }
        $this->assertSame(['failed', 7], [$this->db->table('webhook_deliveries')->where('id', $id)->value('status'), (int) $this->db->table('webhook_deliveries')->where('id', $id)->value('attempts')]);
        $this->assertTrue((bool) $this->cms->webhooks->find($made['id'])['active']);

        // … and turn the webhook off when that makes DISABLE_AFTER in a row.
        $this->db->table('webhook_deliveries')->where('id', $id)->update(['status' => 'retrying', 'attempts' => count(Webhooks::BACKOFF)]);
        $this->cms->webhooks->attempt($id);
        $hook = $this->cms->webhooks->find($made['id']);
        $this->assertFalse($hook['active']);
        $this->assertStringContainsString('in a row failed', (string) $hook['disabled_reason']);
        $this->assertSame(1, $this->db->table('notifications')->where('type', 'webhook-disabled')->count(), 'the administrator is told');
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'webhook.disabled')->count());

        $sent = count($this->fake->sent);
        $this->api('POST', '/content/post/entries', ['title' => 'Three']);
        $this->assertCount($sent, $this->fake->sent, 'nothing goes to a webhook that is off');

        $hook = $this->json($this->api('PATCH', "/webhooks/{$made['id']}", ['active' => true]))['data'];
        $this->assertSame([true, 0, null], [$hook['active'], $hook['failure_streak'], $hook['disabled_reason']]);
    }

    public function testPermanentFailuresAreNotRetried(): void
    {
        $made = $this->hook(['entry.created']);
        // The address later points somewhere private (DNS rebinding): blocked, and not tried again.
        $this->db->table('webhooks')->where('id', $made['id'])->update(['url' => 'https://internal.example.com/x']);
        $this->api('POST', '/content/post/entries', ['title' => 'One']);
        $row = $this->db->table('webhook_deliveries')->first();
        $this->assertSame(['failed', 1], [$row['status'], (int) $row['attempts']]);
        $this->assertCount(0, $this->fake->sent);
    }

    public function testRotationPingAndRedeliver(): void
    {
        $made = $this->hook(['entry.created']);
        $r = $this->api('POST', "/webhooks/{$made['id']}/secret");
        $this->assertSame(200, $r->statusCode(), $r->content());
        $new = $this->json($r)['data']['secret'];
        $this->assertFalse($made['secret'] === $new);

        $ping = $this->json($this->api('POST', "/webhooks/{$made['id']}/ping"))['data'];
        $this->assertSame(['webhook.ping', 'succeeded', 200], [$ping['event'], $ping['status'], $ping['response_status']]);
        $req = end($this->fake->sent);
        $sig = array_change_key_case($req->headers)['kestello-signature'];
        $this->assertTrue(Signature::verify($sig, (string) $req->body, $new, now: Clock::timestamp()));
        $this->assertTrue(Signature::verify($sig, (string) $req->body, $made['secret'], now: Clock::timestamp()), 'the old secret still works for a day');

        Clock::travel(Webhooks::ROTATION_GRACE + 1);
        $again = $this->json($this->api('POST', "/webhooks/{$made['id']}/deliveries/{$ping['id']}/redeliver"))['data'];
        $this->assertSame($ping['event_id'], $again['event_id'], 'a redelivery keeps the event id');
        $this->assertFalse($ping['id'] === $again['id']);
        $req = end($this->fake->sent);
        $this->assertFalse(Signature::verify(array_change_key_case($req->headers)['kestello-signature'], (string) $req->body, $made['secret'], now: Clock::timestamp()), 'and not after');

        $this->assertSame(404, $this->api('POST', "/webhooks/{$made['id']}/deliveries/999/redeliver")->statusCode());
        $this->assertSame(204, $this->api('DELETE', "/webhooks/{$made['id']}")->statusCode());
        $this->assertSame(0, $this->db->table('webhook_deliveries')->count(), 'its log goes with it');
    }

    public function testAnswersMatchTheirDeclaredShapes(): void
    {
        $r = $this->api('POST', '/webhooks', ['name' => 'Receiver', 'url' => self::URL, 'events' => ['entry.created']]);
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\CreateWebhook::class, $r);
        $id = $this->json($r)['data']['id'];
        $this->api('POST', '/content/post/entries', ['title' => 'One']);
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\ListWebhooks::class, $this->api('GET', '/webhooks'));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\ShowWebhook::class, $this->api('GET', "/webhooks/{$id}"));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\ListWebhookEvents::class, $this->api('GET', '/webhooks/events'));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\ListWebhookDeliveries::class, $this->api('GET', "/webhooks/{$id}/deliveries"));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\PingWebhook::class, $this->api('POST', "/webhooks/{$id}/ping"));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\RotateWebhookSecret::class, $this->api('POST', "/webhooks/{$id}/secret"));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Webhooks\UpdateWebhook::class, $this->api('PATCH', "/webhooks/{$id}", ['active' => false]));

        $post = $this->api('POST', '/content/post/entries', ['title' => 'Two', 'data' => ['body' => '<p>Hi</p>']]);
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Content\CreateEntry::class, $post);
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Content\GetEntry::class, $this->api('GET', '/content/post/entries/' . $this->json($post)['data']['id']));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Content\ListEntries::class, $this->api('GET', '/content/post/entries'));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\GetMe::class, $this->api('GET', '/me'));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\ListUsers::class, $this->api('GET', '/users'));
    }

    public function testOnlyAdministratorsManageWebhooks(): void
    {
        $this->createUser(['username' => 'eddie', 'email' => 'eddie@example.test', 'role' => 'editor']);
        $this->api('DELETE', '/auth/session');
        $this->api('POST', '/auth/session', ['login' => 'eddie', 'password' => self::PASSWORD]);
        $this->assertSame(403, $this->api('GET', '/webhooks')->statusCode());
        $this->assertSame(403, $this->api('POST', '/webhooks', ['name' => 'x', 'url' => self::URL, 'events' => ['entry.created']])->statusCode());
    }

    public function testPluginsNameTheirOwnEvents(): void
    {
        ExecutionContext::run('plugin:acme-shop', fn() => $this->cms->webhooks->event('acme-shop.order-paid', 'An order was paid', 'Shop'));
        $this->assertArrayHasKey('acme-shop.order-paid', $this->cms->webhooks->events());
        $this->assertThrows(LogicException::class, fn() => ExecutionContext::run('plugin:acme-shop', fn() => $this->cms->webhooks->event('entry.deleted', 'Not mine')));
    }

    public function testOthersCannotSendAPluginsEvent(): void
    {
        ExecutionContext::run('plugin:acme-shop', fn() => $this->cms->webhooks->event('acme-shop.order-paid', 'An order was paid'));
        $this->hook(['acme-shop.order-paid']);
        $this->assertNotNull(ExecutionContext::run('plugin:acme-shop', fn() => $this->cms->webhooks->dispatch('acme-shop.order-paid', ['order' => 1])));
        $this->assertThrows(LogicException::class, fn() => ExecutionContext::run('plugin:other', fn() => $this->cms->webhooks->dispatch('acme-shop.order-paid', ['order' => 2])));
    }

    /** @return array<string, mixed> the webhook, with its secret */
    private function hook(array $events): array
    {
        $r = $this->api('POST', '/webhooks', ['name' => 'Receiver', 'url' => self::URL, 'events' => $events]);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }
}
