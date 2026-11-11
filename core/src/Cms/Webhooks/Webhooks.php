<?php

declare(strict_types=1);

namespace Kestello\Cms\Webhooks;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Http\Client\HttpBlocked;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Support\Clock;
use Kestello\Support\Crypt;
use Kestello\Support\Json;
use Kestello\Support\Str;
use Kestello\Support\Translator;
use LogicException;
use Throwable;

/**
 * Outgoing webhooks ($cms->webhooks): tell other services when something
 * happens on the site, by POSTing a signed JSON message to their URL.
 *
 *   // a plugin offers an event (once, in boot()), and sends it
 *   $cms->webhooks->event('acme-shop.order-paid', 'An order was paid', 'Shop');
 *   $cms->webhooks->dispatch('acme-shop.order-paid', ['order' => 42, 'total' => '19.90']);
 *
 * Every webhook subscribed to the event gets one delivery. Deliveries run
 * in the background, one attempt per job; a failed one is tried again after
 * 1, 5 and 30 minutes, then 2, 6 and 12 hours (about 21 hours in all). A
 * webhook whose deliveries give up DISABLE_AFTER times in a row is turned
 * off, and administrators are told.
 *
 * The body is {"id", "type", "created_at", "data"}; the header
 * "Kestello-Signature: t=<unix>,v1=<hex>" is HMAC-SHA256 of "<t>.<body>"
 * with the webhook's secret (Signature::verify checks one).
 *
 * @api
 */
final class Webhooks
{
    /** Seconds before attempt 2, 3, … (7 attempts in all). */
    public const array BACKOFF = [60, 300, 1800, 7200, 21600, 43200];

    /** Deliveries in a row that give up before the webhook is turned off. */
    public const int DISABLE_AFTER = 10;

    /** After a rotation, the old secret still signs for this long. */
    public const int ROTATION_GRACE = 86400;

    /** Payloads larger than this are sent without their data. */
    public const int MAX_PAYLOAD = 262_144;

    public const int MAX_WEBHOOKS = 50;

    private const int RESPONSE_KEEP = 2048;

    /** @var array<string, array{label: string, group: string, description: string, owner: string}> */
    private array $events = [];

    /** @var (Closure(): HttpClient)|null */
    private ?Closure $http = null;

    // ── the event catalog ────────────────────────────────────────────

    /**
     * Offer an event webhooks can subscribe to. Plugins name theirs
     * "<slug>.<event>"; core uses entry.*, form.*, user.* and webhook.*.
     */
    public function event(string $name, string $label, string $group = '', string $description = ''): void
    {
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9_-]+)+$/', $name) !== 1 || strlen($name) > 100) {
            throw new LogicException("Webhook event [{$name}] must look like \"group.event\".");
        }
        $owner = ExecutionContext::current();
        if ($owner !== 'core') {
            $slug = explode(':', $owner, 2)[1] ?? '';
            if (!str_starts_with($name, $slug . '.')) {
                throw new LogicException("{$owner} webhook events must start with \"{$slug}.\", got \"{$name}\".");
            }
        }
        if (isset($this->events[$name]) && $this->events[$name]['owner'] !== $owner) {
            throw new LogicException("Webhook event [{$name}] is already offered by {$this->events[$name]['owner']}.");
        }
        $this->events[$name] = ['label' => $label, 'group' => $group, 'description' => $description, 'owner' => $owner];
    }

    /** @return array<string, array{label: string, group: string, description: string, owner: string}> */
    public function events(): array
    {
        return $this->events;
    }

    /** @internal Kernel: how deliveries reach the network (tests pass a fake). */
    public function useHttp(Closure $http): void
    {
        $this->http = $http;
    }

    // ── sending ──────────────────────────────────────────────────────

    /**
     * Queue the event for every active webhook subscribed to it. Returns
     * the event id ("evt_…", the same for every webhook), or null when no
     * webhook wants it.
     *
     * @param array<string, mixed> $data
     */
    public function dispatch(string $event, array $data): ?string
    {
        if (!isset($this->events[$event])) {
            throw new LogicException("Unknown webhook event [{$event}]: offer it with \$cms->webhooks->event() first.");
        }
        $owner = ExecutionContext::current();
        if ($owner !== 'core' && $this->events[$event]['owner'] !== $owner) {
            throw new LogicException("{$owner} may not send {$this->events[$event]['owner']}'s event [{$event}].");
        }
        $hooks = array_values(array_filter($this->all(activeOnly: true), static fn(array $w) => in_array($event, $w['events'], true) || in_array('*', $w['events'], true)));
        if ($hooks === []) {
            return null;
        }
        [$id, $payload] = self::payload($event, $data);
        foreach ($hooks as $hook) {
            $this->queue($this->record((int) $hook['id'], $id, $event, $payload));
        }

        return $id;
    }

    /**
     * Make one attempt at a delivery. Returns the delivery as it is now.
     * $force tries even an inactive webhook (test pings, redeliveries).
     *
     * @return array<string, mixed>|null
     */
    public function attempt(int $deliveryId, bool $force = false): ?array
    {
        // Claim it: two workers never send the same attempt.
        $claimed = QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->whereIn('status', ['pending', 'retrying'])->update(['status' => 'sending']);
        $row = QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->first();
        if ($row === null || $claimed === 0) {
            return $row === null ? null : self::presentDelivery($row);
        }
        $hook = QueryBuilder::table('webhooks')->where('id', $row['webhook_id'])->first();
        if ($hook === null || (!$hook['active'] && !$force)) {
            QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'error' => 'The webhook is turned off.', 'next_attempt_at' => null]);

            return $this->delivery($deliveryId);
        }

        $attempt = (int) $row['attempts'] + 1;
        $body = (string) $row['payload'];
        $started = microtime(true);
        $status = null;
        $responseBody = null;
        $error = null;
        $permanent = false;
        try {
            $response = $this->client()->post((string) $hook['url'], [
                'body' => $body,
                'headers' => $this->headers($hook, $row, $body),
                'timeout' => max(1, (int) (Cms::instance()?->config->get('webhooks.timeout', 10) ?? 10)),
                'connect_timeout' => 5,
                'max_redirects' => 0,
                'max_bytes' => 65_536,
                'allow_private' => self::allowPrivate(),
            ]);
            $status = $response->status;
            $responseBody = self::redact($response->body);
            if ($status < 200 || $status > 299) {
                $error = $status >= 300 && $status < 400 ? "Answered {$status} (a redirect, which webhooks do not follow)." : "Answered {$status}.";
            }
        } catch (HttpBlocked $e) {
            $error = $e->getMessage();
            $permanent = true;              // the address is not allowed: trying again changes nothing
        } catch (Throwable $e) {
            $error = mb_strimwidth($e->getMessage(), 0, 400, '…');
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $now = Model::freshTimestamp();
        $update = ['attempts' => $attempt, 'response_status' => $status, 'response_body' => $responseBody, 'duration_ms' => $ms, 'error' => $error];

        if ($error === null) {
            QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->update($update + ['status' => 'succeeded', 'delivered_at' => $now, 'next_attempt_at' => null]);
            QueryBuilder::table('webhooks')->where('id', $hook['id'])->update(['failure_streak' => 0, 'last_success_at' => $now]);
        } elseif (!$permanent && $attempt <= count(self::BACKOFF)) {
            $next = Clock::now(new \DateTimeZone('UTC'))->modify('+' . self::BACKOFF[$attempt - 1] . ' seconds')->format('Y-m-d H:i:s');
            QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->update($update + ['status' => 'retrying', 'next_attempt_at' => $next]);
            QueryBuilder::table('webhooks')->where('id', $hook['id'])->update(['last_failure_at' => $now]);
        } else {
            QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->update($update + ['status' => 'failed', 'next_attempt_at' => null]);
            $this->gaveUp($hook, $now);
        }

        return $this->delivery($deliveryId);
    }

    /** Scheduled every minute: queue the retries that are due. Returns how many. */
    public function retryDue(int $limit = 200): int
    {
        $due = QueryBuilder::table('webhook_deliveries')
            ->join('webhooks', 'webhooks.id', '=', 'webhook_deliveries.webhook_id')
            ->where('webhook_deliveries.status', 'retrying')
            ->where('webhook_deliveries.next_attempt_at', '<=', Model::freshTimestamp())
            ->where('webhooks.active', true)
            ->orderBy('webhook_deliveries.next_attempt_at')->limit($limit)
            ->pluck('webhook_deliveries.id');
        foreach ($due as $id) {
            $this->queue((int) $id);
        }

        return count($due);
    }

    /** Deletes deliveries older than $days. */
    public function prune(int $days = 30): int
    {
        $before = Clock::now(new \DateTimeZone('UTC'))->modify("-{$days} days")->format('Y-m-d H:i:s');

        return QueryBuilder::table('webhook_deliveries')->where('created_at', '<', $before)->whereIn('status', ['succeeded', 'failed'])->delete();
    }

    // ── managing webhooks ────────────────────────────────────────────

    /**
     * @param array{name?: mixed, url?: mixed, events?: mixed, active?: mixed} $input
     * @return array{webhook: array<string, mixed>, secret: string}
     */
    public function create(array $input, ?int $userId): array
    {
        if (QueryBuilder::table('webhooks')->count() >= self::MAX_WEBHOOKS) {
            throw new WebhookException(['url' => ['A site can have at most ' . self::MAX_WEBHOOKS . ' webhooks.']]);
        }
        $clean = $this->validate($input, null);
        $secret = self::newSecret();
        $now = Model::freshTimestamp();
        $id = QueryBuilder::table('webhooks')->insert($clean + [
            'secret' => Crypt::encryptString($secret, 'webhook'), 'active' => $clean['active'] ?? true, 'failure_streak' => 0,
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['webhook' => $this->find($id) ?? [], 'secret' => $secret];
    }

    /** @param array{name?: mixed, url?: mixed, events?: mixed, active?: mixed} $input */
    public function update(int $id, array $input): array
    {
        $hook = $this->find($id) ?? throw new WebhookException([], 'No such webhook.', 404);
        $clean = $this->validate($input, $hook);
        if (($clean['active'] ?? false) === true && !$hook['active']) {
            $clean += ['failure_streak' => 0, 'disabled_reason' => null];     // turning it back on starts afresh
        }
        if (array_key_exists('active', $clean) && $clean['active'] === false) {
            $clean['disabled_reason'] = null;
        }
        if ($clean !== []) {
            QueryBuilder::table('webhooks')->where('id', $id)->update($clean + ['updated_at' => Model::freshTimestamp()]);
        }

        return $this->find($id) ?? [];
    }

    /** A new secret; the old one keeps signing for ROTATION_GRACE seconds, so receivers can switch without missing deliveries. */
    public function rotate(int $id): string
    {
        $row = QueryBuilder::table('webhooks')->where('id', $id)->first() ?? throw new WebhookException([], 'No such webhook.', 404);
        $secret = self::newSecret();
        QueryBuilder::table('webhooks')->where('id', $id)->update([
            'secret' => Crypt::encryptString($secret, 'webhook'),
            'previous_secret' => $row['secret'],
            'previous_expires_at' => Clock::now(new \DateTimeZone('UTC'))->modify('+' . self::ROTATION_GRACE . ' seconds')->format('Y-m-d H:i:s'),
            'updated_at' => Model::freshTimestamp(),
        ]);

        return $secret;
    }

    public function delete(int $id): bool
    {
        return QueryBuilder::table('webhooks')->where('id', $id)->delete() > 0;
    }

    /** Send a webhook.ping now, even to an inactive webhook, and return the delivery. */
    public function ping(int $id): array
    {
        $this->find($id) ?? throw new WebhookException([], 'No such webhook.', 404);
        [$eventId, $payload] = self::payload('webhook.ping', ['webhook' => $id, 'message' => 'This is a test from Kestello.']);

        return $this->attempt($this->record($id, $eventId, 'webhook.ping', $payload), force: true) ?? [];
    }

    /** Send a delivery's message again, as a new delivery (same event id, so receivers can tell it is a repeat). */
    public function redeliver(int $webhookId, int $deliveryId): array
    {
        $row = QueryBuilder::table('webhook_deliveries')->where('webhook_id', $webhookId)->where('id', $deliveryId)->first()
            ?? throw new WebhookException([], 'No such delivery.', 404);

        return $this->attempt($this->record($webhookId, (string) $row['event_id'], (string) $row['event'], (string) $row['payload']), force: true) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $q = QueryBuilder::table('webhooks')->orderBy('name')->orderBy('id');
        if ($activeOnly) {
            $q->where('active', true);
        }

        return array_map(self::present(...), $q->get());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = QueryBuilder::table('webhooks')->where('id', $id)->first();

        return $row === null ? null : self::present($row);
    }

    /**
     * Newest first; $beforeId pages back.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $webhookId, int $limit = 25, ?int $beforeId = null, ?string $status = null): array
    {
        $q = QueryBuilder::table('webhook_deliveries')->where('webhook_id', $webhookId)->orderByDesc('id')->limit($limit);
        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return array_map(self::presentDelivery(...), $q->get());
    }

    /** @return array<string, mixed>|null */
    public function delivery(int $id): ?array
    {
        $row = QueryBuilder::table('webhook_deliveries')->where('id', $id)->first();

        return $row === null ? null : self::presentDelivery($row);
    }

    // ── internals ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed> columns to write
     */
    private function validate(array $input, ?array $current): array
    {
        $errors = [];
        $out = [];
        if ($current === null || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) {
                $errors['name'][] = $name === '' ? 'Give it a name.' : 'Must be at most 100 characters.';
            }
            $out['name'] = $name;
        }
        if ($current === null || array_key_exists('url', $input)) {
            $url = trim((string) ($input['url'] ?? ''));
            if ($message = $this->urlProblem($url)) {
                $errors['url'][] = $message;
            }
            $out['url'] = $url;
        }
        if ($current === null || array_key_exists('events', $input)) {
            $events = array_values(array_unique(array_map('strval', (array) ($input['events'] ?? []))));
            if ($events === []) {
                $errors['events'][] = Translator::get('Pick at least one event.');
            }
            foreach ($events as $event) {
                if ($event !== '*' && !isset($this->events[$event]) && !in_array($event, $current['events'] ?? [], true)) {
                    $errors['events'][] = Translator::get('Unknown event "{event}".', ['event' => $event]);
                }
            }
            $out['events'] = Json::encode($events);
        }
        if (array_key_exists('active', $input)) {
            $out['active'] = (bool) $input['active'];
        }
        if ($errors !== []) {
            throw new WebhookException($errors);
        }

        return $out;
    }

    private function urlProblem(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2048) {
            return $url === '' ? 'Enter the address to send to.' : 'Must be at most 2048 characters.';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && self::allowPrivate())) {
            return 'Use an https:// address, so what is sent cannot be read on the way.';
        }
        try {
            $this->client()->vet($url, 'core', self::allowPrivate());
        } catch (Throwable $e) {
            // the client's own reason is for logs; people get the rule and the host it refused
            return $e instanceof HttpBlocked
                ? 'This address is not allowed: webhooks only go to public servers on ports 80 and 443, and ' . (parse_url($url, PHP_URL_HOST) ?: $url) . ' is not one.'
                : 'This address could not be found. Check it for typos.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $hook
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function headers(array $hook, array $row, string $body): array
    {
        $t = Clock::timestamp();
        $signatures = ['v1=' . Signature::sign(Crypt::decryptString((string) $hook['secret'], 'webhook'), $t, $body)];
        if ($hook['previous_secret'] !== null && $hook['previous_expires_at'] !== null && strtotime($hook['previous_expires_at'] . ' UTC') > $t) {
            $signatures[] = 'v1=' . Signature::sign(Crypt::decryptString((string) $hook['previous_secret'], 'webhook'), $t, $body);
        }

        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Kestello-Webhooks/1',
            'Kestello-Signature' => 't=' . $t . ',' . implode(',', $signatures),
            'Kestello-Event' => (string) $row['event'],
            'Kestello-Event-Id' => (string) $row['event_id'],
            'Kestello-Delivery' => (string) $row['id'],
        ];
    }

    /** @param array<string, mixed> $hook */
    private function gaveUp(array $hook, string $now): void
    {
        $streak = (int) $hook['failure_streak'] + 1;
        $off = $streak >= self::DISABLE_AFTER && $hook['active'];
        QueryBuilder::table('webhooks')->where('id', $hook['id'])->update(['failure_streak' => $streak, 'last_failure_at' => $now] + ($off ? [
            'active' => false, 'disabled_reason' => "Turned off after {$streak} deliveries in a row failed.",
        ] : []));
        if (!$off) {
            return;
        }
        // Its other waiting deliveries stop here too.
        QueryBuilder::table('webhook_deliveries')->where('webhook_id', $hook['id'])->whereIn('status', ['pending', 'retrying'])
            ->update(['status' => 'failed', 'error' => 'The webhook was turned off.', 'next_attempt_at' => null]);
        $cms = Cms::instance();
        if ($cms !== null) {
            $cms->audit->record('webhook.disabled', ['webhook', (int) $hook['id']], ['name' => $hook['name'], 'failures' => $streak]);
            $cms->notifications->send(\Kestello\Cms\Health\Administrators::ids($cms), new WebhookDisabledNotification((int) $hook['id'], (string) $hook['name'], $streak, (string) $cms->config->get('app.url', '')));
        }
    }

    private function record(int $webhookId, string $eventId, string $event, string $payload): int
    {
        return QueryBuilder::table('webhook_deliveries')->insert([
            'webhook_id' => $webhookId, 'event_id' => $eventId, 'event' => $event, 'payload' => $payload,
            'status' => 'pending', 'attempts' => 0, 'created_at' => Model::freshTimestamp(),
        ]);
    }

    private function queue(int $deliveryId): void
    {
        QueryBuilder::table('webhook_deliveries')->where('id', $deliveryId)->where('status', 'retrying')->update(['status' => 'pending']);
        $cms = Cms::instance();
        if ($cms === null) {
            $this->attempt($deliveryId);
            return;
        }
        ExecutionContext::run('core', fn() => $cms->queue->push(new DeliverWebhook($deliveryId)));
    }

    private function client(): HttpClient
    {
        return $this->http !== null ? ($this->http)() : (Cms::instance()?->container->make(HttpClient::class) ?? new HttpClient());
    }

    private static function allowPrivate(): bool
    {
        return (bool) (Cms::instance()?->config->get('webhooks.allow_private', false) ?? false);
    }

    /** @return array{0: string, 1: string} event id, JSON body */
    private static function payload(string $event, array $data): array
    {
        $id = 'evt_' . str_replace('-', '', Str::uuid7());
        $message = ['id' => $id, 'type' => $event, 'created_at' => Clock::now(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'data' => $data];
        $body = Json::encode($message);
        if (strlen($body) > self::MAX_PAYLOAD) {
            $message['data'] = ['truncated' => true, 'reason' => 'The data was larger than ' . self::MAX_PAYLOAD . ' bytes; fetch it through the API.'];
            $body = Json::encode($message);
        }

        return [$id, $body];
    }

    private static function newSecret(): string
    {
        return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** The start of a response, with anything that looks like a credential masked. */
    private static function redact(string $body): string
    {
        $body = mb_strcut($body, 0, self::RESPONSE_KEEP);
        $body = (string) preg_replace('/("?(?:token|secret|password|api[_-]?key|authorization|access[_-]?key)"?\s*[:=]\s*"?)[^"\s,&}]+/i', '$1[hidden]', $body);

        return (string) preg_replace('/\b(?:Bearer\s+)?[A-Za-z0-9_\-]{32,}\b/', '[hidden]', $body);
    }

    /** @param array<string, mixed> $row */
    private static function present(array $row): array
    {
        $last = QueryBuilder::table('webhook_deliveries')->where('webhook_id', $row['id'])->orderByDesc('id')->first();

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'url' => (string) $row['url'],
            'events' => Json::decode((string) $row['events']) ?? [],
            'active' => (bool) $row['active'],
            'disabled_reason' => $row['disabled_reason'],
            'failure_streak' => (int) $row['failure_streak'],
            'secret_rotating_until' => self::iso($row['previous_expires_at'] !== null && strtotime($row['previous_expires_at'] . ' UTC') > Clock::timestamp() ? $row['previous_expires_at'] : null),
            'last_success_at' => self::iso($row['last_success_at']),
            'last_failure_at' => self::iso($row['last_failure_at']),
            'last_delivery' => $last === null ? null : ['id' => (int) $last['id'], 'event' => $last['event'], 'status' => $last['status'], 'response_status' => $last['response_status'] === null ? null : (int) $last['response_status'], 'created_at' => self::iso($last['created_at'])],
            'created_at' => self::iso($row['created_at']),
            'updated_at' => self::iso($row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function presentDelivery(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'webhook_id' => (int) $row['webhook_id'],
            'event_id' => (string) $row['event_id'],
            'event' => (string) $row['event'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'response_status' => $row['response_status'] === null ? null : (int) $row['response_status'],
            'response_body' => $row['response_body'],
            'error' => $row['error'],
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'payload' => Json::decode((string) $row['payload']),
            'next_attempt_at' => self::iso($row['next_attempt_at']),
            'created_at' => self::iso($row['created_at']),
            'delivered_at' => self::iso($row['delivered_at']),
        ];
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : str_replace(' ', 'T', (string) $value) . 'Z';
    }
}
