<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Auth\Guards\SessionGuard;
use Kestello\Cms\Cms;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Framework\Session\DatabaseSessionStore;
use Kestello\Framework\Session\Session;
use Kestello\Framework\Session\SessionMetadata;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\AppKey;
use Kestello\Support\Hash;
use Kestello\Tests\Support\TestCms;

/**
 * A booted core (SQLite, all migrations, auth services) with a fake
 * session ($_SESSION without PHP's session machinery) and captured mail.
 */
abstract class AuthTestCase extends TestCase
{
    protected const string PASSWORD = 'correct horse battery';

    protected Cms $cms;
    protected Connection $db;
    protected DatabaseSessionStore $sessions;
    /** @var list<array{to: string, subject: string, html: string}> */
    protected array $mail = [];
    protected bool $csrfValid = true;
    protected ?int $sessionUser = null;

    /** Boot the site as a public demo (app.demo). */
    protected bool $demo = false;

    protected function setUp(): void
    {
        $this->db = Connection::sqliteMemory();
        DatabaseManager::setConnection($this->db);
        \Kestello\Framework\Settings\Settings::destroy();     // its cache belongs to the previous test's database
        (new MigrationRunner(dirname(__DIR__, 2) . '/core/database/migrations'))->migrate();

        AppKey::set(bin2hex(random_bytes(32)));
        $_SESSION = [];
        $this->bootCms();
    }

    /**
     * A fresh CMS over the same database and session, like the next request
     * after a deploy. Pass the previous root to keep the same site folder.
     */
    protected function bootCms(?string $root = null): void
    {
        $this->cms = TestCms::make($root ?? $this->tempDir(), csrf: fn(): bool => $this->csrfValid, demo: $this->demo);
        Cms::setInstance($this->cms);
        $c = $this->cms->container;
        $c->instance(Cms::class, $this->cms);
        $c->instance('auth.mail', function (string $to, string $subject, string $html): bool {
            $this->mail[] = compact('to', 'subject', 'html');
            return true;
        });

        $this->sessions = new DatabaseSessionStore($this->db);
        $this->sessions->setMetadataProvider(fn() => new SessionMetadata($this->sessionUser, '198.51.100.9', 'Device/1.0'));
        $c->instance(SessionStore::class, $this->sessions);

        Auth::setInstance(new Auth(new Session()));
        $module = new AuthModule();
        $module->register($c);
        $module->boot($this->cms);
        $this->cms->auth->guard(new SessionGuard($this->cms->auth, static fn(): ?Auth => Auth::getInstance()));
        (new \Kestello\Cms\Api\ApiModule())->boot($this->cms);
    }

    protected function tearDown(): void
    {
        \Kestello\Support\Clock::unfreeze();
        $_SESSION = [];
        Auth::destroy();
        Cms::setInstance(null);
        AppKey::clear();
        DatabaseManager::reset();
        ExecutionContext::reset();
    }

    /** @param array<string, mixed> $overrides */
    protected function createUser(array $overrides = []): array
    {
        $row = $overrides + [
            'username' => 'ana',
            'email' => 'ana@example.test',
            'password' => Hash::password(self::PASSWORD),
            'role' => 'admin',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $id = $this->db->table('users')->insert($row);

        return (array) $this->db->table('users')->where('id', $id)->first();
    }

    /** A fresh Auth over the same $_SESSION: what the next request sees. */
    protected function nextRequest(): Auth
    {
        Auth::setInstance($auth = new Auth(new Session()));

        return $auth;
    }

    /** @param array<string, mixed> $body */
    protected function api(string $method, string $path, array $body = [], string $ip = '203.0.113.7', ?string $raw = null): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $uri = '/api/v1' . $path;
        $request = new Request($method, $uri, (string) parse_url($uri, PHP_URL_PATH), [], $query, $body, [], [], ['REMOTE_ADDR' => $ip], $raw ?? '');

        return $this->cms->routes->dispatch($request);
    }

    /**
     * The answer's data matches what the endpoint declares (response() or
     * resource()), which is what OpenAPI and the TypeScript client promise.
     *
     * @param class-string<\Kestello\Cms\Api\Endpoint> $endpoint
     */
    protected function assertAnswerMatches(string $endpoint, Response $response): void
    {
        $ep = (new \ReflectionClass($endpoint))->newInstanceWithoutConstructor();
        $schema = $ep->response();
        if ($schema === null && $ep->resource() !== null) {
            $props = $ep->resource()::properties();
            $schema = \Kestello\Cms\Api\Schema::object($props);
        }
        $this->assertNotNull($schema, "{$endpoint} declares no answer shape");
        $data = $this->json($response)['data'] ?? null;
        $items = $ep->collection() ? $data : [$data];
        $this->assertTrue(is_array($items) && ($ep->collection() ? array_is_list($items) : true), "{$endpoint}: data should be " . ($ep->collection() ? 'a list' : 'an object'));
        foreach ($items as $i => $item) {
            [, $errors] = $schema->validate($item);
            $this->assertSame([], $errors, "{$endpoint} item {$i} does not match its declared shape: " . json_encode($errors, JSON_UNESCAPED_SLASHES));
        }
    }

    /** @return array<string, mixed> */
    protected function json(Response $response): array
    {
        if ($response->content() === '') {
            return [];
        }
        try {
            return json_decode($response->content(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException("Not JSON (HTTP {$response->statusCode()}): " . mb_substr(strip_tags($response->content()), 0, 400));
        }
    }

    /**
     * One row in each per-person table without a foreign key to users: a waiting email change,
     * an inbox entry and a switched-off channel. Deleting the person must take them along.
     */
    protected function addUnlinkedRows(int $userId): void
    {
        $this->db->table('email_changes')->insert(['user_id' => $userId, 'email' => "new{$userId}@example.test", 'token_hash' => hash('sha256', "token{$userId}"), 'created_at' => time(), 'expires_at' => time() + 86400]);
        $this->db->table('notifications')->insert(['id' => sprintf('00000000-0000-7000-8000-%012d', $userId), 'user_id' => $userId, 'type' => 'order-shipped', 'data' => '{}', 'created_at' => time()]);
        $this->db->table('notification_preferences')->insert(['user_id' => $userId, 'type' => 'order-shipped', 'channel' => 'mail', 'enabled' => false]);
    }

    /** @return array<string, int> table => rows left for the person */
    protected function unlinkedRows(int $userId): array
    {
        $out = [];
        foreach (['email_changes', 'notifications', 'notification_preferences'] as $table) {
            $out[$table] = $this->db->table($table)->where('user_id', $userId)->count();
        }

        return $out;
    }

    /** @return list<string> */
    protected function auditEvents(): array
    {
        return array_reverse(array_column($this->cms->audit->query([], 500), 'event'));
    }
}
