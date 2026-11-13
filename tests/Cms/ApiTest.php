<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Api\ApiModule;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Cursor;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\OpenApi;
use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\TrustedProxies;
use Kestello\Framework\Testing\TestCase;
use Kestello\Tests\Support\FakeGuard;
use Kestello\Tests\Support\TestCms;
use LogicException;

final class ApiTest extends TestCase
{
    private Cms $cms;
    private FakeGuard $session;
    private FakeGuard $bearer;
    private bool $csrfValid = false;

    protected function setUp(): void
    {
        $this->session = new FakeGuard(bearer: false, id: 'session');
        $this->bearer = new FakeGuard(bearer: true, id: 'token');
        $this->cms = TestCms::make($this->tempDir(), csrf: fn(): bool => $this->csrfValid, guards: [$this->session, $this->bearer]);
        Cms::setInstance($this->cms);
    }

    protected function tearDown(): void
    {
        Cms::setInstance(null);
        ExecutionContext::reset();
        TrustedProxies::set([]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function bootCoreApi(): void
    {
        $this->cms->container->instance(Cms::class, $this->cms);
        (new \Kestello\Cms\Auth\AuthModule())->register($this->cms->container);
        (new ApiModule())->boot($this->cms);
    }

    private function resetRateLimits(): void
    {
        $limiter = (new \ReflectionProperty($this->cms->api, 'limiter'))->getValue($this->cms->api);
        (new \ReflectionProperty($limiter, 'store'))->getValue($limiter)->flush();
    }

    private function call(string $method, string $uri, array $body = [], array $headers = []): Response
    {
        $path = parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = new Request($method, $uri, $path, $headers, $query, $body, [], [], ['REMOTE_ADDR' => '203.0.113.7']);

        return $this->cms->routes->dispatch($request);
    }

    private function json(Response $response): array
    {
        return json_decode($response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(string $role = 'admin', ?array $scopes = null, string $method = 'session', ?int $authAt = null): Principal
    {
        return $this->cms->auth->principalFor(
            ['id' => 7, 'username' => 'u', 'email' => 'u@example.test', 'role' => $role],
            $method,
            $scopes,
            authenticatedAt: $authAt ?? time(),
        );
    }

    private function asSession(Principal $principal): void
    {
        $this->session->principal = $principal;
    }

    private function asToken(array $scopes, string $role = 'admin'): array
    {
        $this->bearer->principal = $this->user($role, $scopes, 'token');

        return ['Authorization' => 'Bearer kst_pat_test'];
    }

    private function endpoint(string $method, string $path, array $opts = []): Endpoint
    {
        return new class ($method, $path, $opts) extends Endpoint {
            public function __construct(private string $m, private string $p, private array $o) {}
            public function route(): ApiRoute
            {
                return [ApiRoute::class, strtolower($this->m)]($this->p);
            }
            public function isPublic(): bool
            {
                return $this->o['public'] ?? false;
            }
            public function capability(): ?string
            {
                return $this->o['capability'] ?? null;
            }
            public function scopes(): array
            {
                return $this->o['scopes'] ?? [];
            }
            public function body(): ?Schema
            {
                return $this->o['body'] ?? null;
            }
            public function query(): ?Schema
            {
                return $this->o['query'] ?? null;
            }
            public function rateLimit(): ?array
            {
                return $this->o['limit'] ?? null;
            }
            public function requiresRecentAuth(): bool
            {
                return $this->o['recent'] ?? false;
            }
            public function authorize(ApiRequest $request): bool
            {
                return ($this->o['authorize'] ?? null) === null || ($this->o['authorize'])($request);
            }
            public function response(): ?Schema
            {
                return $this->o['response'] ?? null;
            }
            public function status(): int
            {
                return $this->o['status'] ?? 200;
            }
            public function collection(): bool
            {
                return $this->o['collection'] ?? false;
            }
            public function handle(ApiRequest $request): mixed
            {
                return ($this->o['handle'] ?? fn() => ['ok' => true])($request);
            }
        };
    }

    // ── Answers are what endpoints declare ──────────────────────────

    public function testInDebugModeAnAnswerMustBeWhatItsEndpointDeclares(): void
    {
        $shape = Schema::object(['ok' => Schema::bool()]);
        $this->cms->api->endpoint($this->endpoint('GET', '/right', ['public' => true, 'response' => $shape]));
        $this->cms->api->endpoint($this->endpoint('GET', '/wrong', ['public' => true, 'response' => $shape, 'handle' => fn() => ['ok' => 'yes', 'extra' => 1]]));
        $this->cms->api->endpoint($this->endpoint('POST', '/made', ['public' => true, 'response' => $shape, 'status' => 201]));
        $this->cms->api->endpoint($this->endpoint('POST', '/nothing', ['public' => true, 'status' => 204, 'handle' => fn() => ['ok' => true]]));
        $this->cms->api->endpoint($this->endpoint('GET', '/undeclared', ['public' => true, 'handle' => fn() => ['anything' => 1]]));

        $this->csrfValid = true;
        $this->assertSame(200, $this->call('GET', '/api/v1/right')->statusCode());
        $this->assertSame(201, $this->call('POST', '/api/v1/made')->statusCode(), 'status() sets the status of a created answer');
        $wrong = $this->json($this->call('GET', '/api/v1/wrong'));
        $this->assertSame(500, $wrong['status']);
        $this->assertStringContainsString('answered what it does not declare: ', $wrong['detail']);
        $this->assertStringContainsString('data.ok: ', $wrong['detail'], 'a wrong type');
        $this->assertStringContainsString('data.extra: Unknown field.', $wrong['detail'], 'a response() describes the whole answer');
        $this->assertStringContainsString('answered 200, but status() says 204', $this->json($this->call('POST', '/api/v1/nothing'))['detail']);
        $this->assertSame(200, $this->call('GET', '/api/v1/undeclared')->statusCode(), 'an endpoint that declares nothing is not checked');

        $this->cms->api->endpoint($this->endpoint('GET', '/clash', ['public' => true, 'response' => $shape, 'handle' => fn() => throw \Kestello\Cms\Api\ApiProblem::conflict('taken')]));
        $clash = $this->json($this->call('GET', '/api/v1/clash'));
        $this->assertSame([500, 'answered 409, which problems() does not list'], [$clash['status'], substr($clash['detail'], -strlen('answered 409, which problems() does not list'))], 'a problem it does not document');
    }

    public function testAPluginsWrongAnswerIsLoggedNotRefused(): void
    {
        $previous = \Kestello\Framework\Log\Log::logger();
        $folder = $this->tempDir();
        \Kestello\Framework\Log\Log::use(new \Kestello\Framework\Log\Logger($folder));
        try {
            ExecutionContext::run('plugin:acme', fn() => $this->cms->api->endpoint($this->endpoint('GET', '/acme/count', ['public' => true, 'response' => Schema::object(['n' => Schema::int()]), 'handle' => fn() => ['n' => 'many']])));

            $this->assertSame(200, $this->call('GET', '/api/v1/acme/count')->statusCode(), 'code written before the check keeps working');
            $lines = array_map(static fn(string $l): array => (array) json_decode($l, true), file((string) (glob($folder . '/*.log')[0] ?? ''), FILE_IGNORE_NEW_LINES) ?: []);
            $warning = array_values(array_filter($lines, static fn(array $l): bool => str_contains((string) ($l['message'] ?? ''), 'answered what it does not declare')))[0] ?? [];
            $this->assertSame(['warning', 'plugin:acme'], [$warning['level'] ?? null, $warning['owner'] ?? null], 'its author sees it in the log');
        } finally {
            \Kestello\Framework\Log\Log::use($previous);
        }
    }

    public function testOutsideDebugModeAnswersAreSentAsTheyAre(): void
    {
        $cms = TestCms::make($this->tempDir(), debug: false);
        $cms->api->endpoint($this->endpoint('GET', '/wrong', ['public' => true, 'response' => Schema::object(['ok' => Schema::bool()]), 'handle' => fn() => ['ok' => 'yes']]));

        $response = $cms->routes->dispatch(new Request('GET', '/api/v1/wrong', '/api/v1/wrong', [], [], [], [], [], []));
        $this->assertSame([200, ['data' => ['ok' => 'yes']]], [$response->statusCode(), $this->json($response)], 'a site in production keeps working');
    }

    // ── Registration rules (deny by default) ────────────────────────

    public function testEndpointWithoutCapabilityOrPublicIsRefused(): void
    {
        $this->assertThrows(LogicException::class, fn() => $this->cms->api->endpoint($this->endpoint('GET', '/x')));
        $this->assertThrows(LogicException::class, fn() => $this->cms->api->endpoint($this->endpoint('GET', '/x', ['capability' => 'read'])));
        $this->assertThrows(LogicException::class, fn() => $this->cms->api->endpoint($this->endpoint('GET', '/x', ['capability' => 'read', 'scopes' => ['nope:read']])));
        $this->assertThrows(LogicException::class, fn() => $this->cms->api->endpoint($this->endpoint('GET', '/x', ['public' => true, 'capability' => 'read'])));
        $this->assertCount(0, $this->cms->api->endpoints());
    }

    public function testRefusedEndpointsAreSkippedOutsideDebug(): void
    {
        $cms = TestCms::make($this->tempDir(), debug: false);
        $log = ini_set('error_log', $this->tempDir() . '/php.log');

        $cms->api->endpoint($this->endpoint('GET', '/x'));

        ini_set('error_log', (string) $log);
        $this->assertCount(0, $cms->api->endpoints());
        $this->assertSame(404, $cms->routes->dispatch(new Request('GET', '/api/v1/x', '/api/v1/x', [], [], [], [], [], []))->statusCode());
    }

    public function testPluginEndpointsAndScopesLiveInTheirNamespace(): void
    {
        ExecutionContext::run('plugin:acme', function (): void {
            $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->api->scope('content:nuke', 'no'));
            $this->cms->api->scope('acme:read', 'Read acme');

            $this->assertThrows(LogicException::class, fn() => $this->cms->api->endpoint($this->endpoint('GET', '/users/all', ['capability' => 'read', 'scopes' => ['acme:read']])));
            $this->cms->api->endpoint($this->endpoint('GET', '/acme/things', ['capability' => 'read', 'scopes' => ['acme:read']]));
        });

        $this->assertCount(1, $this->cms->api->endpoints());
        $this->assertSame('plugin:acme', $this->cms->api->endpoints()[0]['owner']);
    }

    public function testDuplicateRoutesAreRefused(): void
    {
        $this->cms->api->endpoint($this->endpoint('GET', '/dup', ['public' => true]));
        $this->assertThrows(LogicException::class, fn() => $this->cms->api->endpoint($this->endpoint('GET', '/dup', ['public' => true])));
    }

    // ── Contract tests over every core endpoint ─────────────────────

    public function testEveryProtectedCoreEndpointRejectsGuestsAndWrongScopes(): void
    {
        $this->bootCoreApi();
        $checked = 0;

        foreach ($this->cms->api->endpoints() as ['endpoint' => $endpoint, 'route' => $route]) {
            if ($endpoint->isPublic()) {
                continue;
            }

            $path = '/api/v1' . preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($route): string {
                $constraint = $route->constraints[$m[1]] ?? '[^/]+';
                $first = preg_match('/^\(\?:([a-z_-]+)\|/', $constraint, $alt) === 1 ? [$alt[1]] : [];   // (?:template|part|...)
                if (preg_match('/^([a-z]+-)\[/', $constraint, $lit) === 1) {
                    $first[] = $lit[1] . 'a';                                                          // gf-[a-z0-9-]+
                }
                foreach ([...$first, '1', str_repeat('a', 64), str_repeat('a', 32), 'a', 'a.zip'] as $sample) {
                    if (preg_match('#^' . $constraint . '$#', $sample) === 1) {
                        return $sample;
                    }
                }
                return '1';
            }, $route->path);
            $label = $route->method . ' ' . $route->path;
            $this->csrfValid = true;
            $this->resetRateLimits(); // dozens of endpoints share one write bucket per user

            $this->session->principal = null;
            $this->bearer->principal = null;
            $this->assertSame(401, $this->call($route->method, $path)->statusCode(), "{$label}: guest must get 401");

            $headers = $this->asToken(['unrelated:scope']);
            $this->assertSame(403, $this->call($route->method, $path, [], $headers)->statusCode(), "{$label}: wrong scope must get 403");

            $this->bearer->principal = null;
            $this->asSession($this->user('nobody'));
            $this->assertSame(403, $this->call($route->method, $path)->statusCode(), "{$label}: missing capability must get 403");

            $checked++;
        }

        $this->assertTrue($checked >= 5, "only {$checked} protected endpoints checked");
    }

    public function testGuestOnlySeesPublicEndpointsInOpenApi(): void
    {
        $this->bootCoreApi();

        $guest = (new OpenApi($this->cms->api))->build(Principal::guest());
        $admin = (new OpenApi($this->cms->api))->build($this->user());

        $this->assertSame(['/auth/2fa/verify', '/auth/password/forgot', '/auth/password/reset', '/auth/providers', '/auth/session', '/forms/{id}/submissions', '/forms/{id}/token', '/openapi.json', '/openapi.ts', '/search', '/site', '/status'], array_keys($guest['paths']));
        $this->assertFalse(isset($guest['paths']['/auth/session']['delete']), 'signing out is not public');
        $this->assertContains('/users/{id}', array_keys($admin['paths']));
        $this->assertSame(['users:read'], $admin['paths']['/users']['get']['security'][1]['bearer']);
        $this->assertSame('integer', $admin['paths']['/users/{id}']['get']['parameters'][0]['schema']['type']);
        $this->assertSame('deepObject', $admin['paths']['/users']['get']['parameters'][3]['style']);
    }

    // ── Pipeline ────────────────────────────────────────────────────

    public function testCookieWritesNeedCsrfButBearerWritesDoNot(): void
    {
        $this->cms->api->endpoint($this->endpoint('POST', '/things', ['capability' => 'read', 'scopes' => ['profile']]));
        $this->asSession($this->user());

        $response = $this->call('POST', '/api/v1/things');
        $this->assertSame(403, $response->statusCode());
        $this->assertSame('urn:kestello:problem:csrf_mismatch', $this->json($response)['type']);
        $this->assertContains('application/problem+json', $response->headers()['Content-Type']);

        $this->csrfValid = true;
        $this->assertSame(200, $this->call('POST', '/api/v1/things')->statusCode());

        $this->csrfValid = false;
        $this->assertSame(200, $this->call('POST', '/api/v1/things', [], $this->asToken(['profile']))->statusCode());
    }

    public function testBearerRequestsNeverFallBackToTheSession(): void
    {
        $this->cms->api->endpoint($this->endpoint('GET', '/mine', ['capability' => 'read', 'scopes' => ['profile']]));
        $this->asSession($this->user());

        $response = $this->call('GET', '/api/v1/mine', [], ['Authorization' => 'Bearer kst_pat_forged']);

        $this->assertSame(401, $response->statusCode());
        $this->assertSame('urn:kestello:problem:invalid_token', $this->json($response)['type']);
    }

    public function testAdminScopeCoversEverythingButNeverWidensTheRole(): void
    {
        $this->cms->api->endpoint($this->endpoint('GET', '/users-ish', ['capability' => 'users.read', 'scopes' => ['users:read']]));

        $this->assertSame(200, $this->call('GET', '/api/v1/users-ish', [], $this->asToken(['admin']))->statusCode());
        $this->assertSame(403, $this->call('GET', '/api/v1/users-ish', [], $this->asToken(['admin'], role: 'user'))->statusCode());
    }

    public function testRecentAuthenticationRules(): void
    {
        $this->cms->api->endpoint($this->endpoint('GET', '/danger', ['capability' => 'read', 'scopes' => ['profile'], 'recent' => true]));

        $this->asSession($this->user(authAt: time() - 3600));
        $stale = $this->call('GET', '/api/v1/danger');
        $this->assertSame(401, $stale->statusCode());
        $this->assertTrue($this->json($stale)['reauth_required']);

        $this->asSession($this->user(authAt: time() - 60));
        $this->assertSame(200, $this->call('GET', '/api/v1/danger')->statusCode());

        $this->assertSame(403, $this->call('GET', '/api/v1/danger', [], $this->asToken(['admin']))->statusCode());
    }

    public function testBodyAndQueryAreValidatedStrictly(): void
    {
        $seen = null;
        $this->cms->api->endpoint($this->endpoint('POST', '/books', [
            'public' => true,
            'body' => Schema::object(['title' => Schema::string()->min(1)->max(10), 'tags' => Schema::list(Schema::string())->max(2)->optional()]),
            'query' => Schema::object(['draft' => Schema::bool()->default(false)]),
            'handle' => function (ApiRequest $r) use (&$seen) {
                $seen = [$r->body, $r->query];
                return null;
            },
        ]));
        $this->csrfValid = true;

        $bad = $this->call('POST', '/api/v1/books?draft=maybe&x=1', ['title' => '', 'role' => 'admin', 'tags' => ['a', 'b', 'c']]);
        $this->assertSame(422, $bad->statusCode());
        $errors = $this->json($bad)['errors'];
        $this->assertSame(['query.draft', 'query.x', 'role', 'tags', 'title'], (static function (array $k) {
            sort($k);
            return $k;
        })(array_keys($errors)));
        $this->assertContains('Unknown field.', $errors['role']);
        $this->assertContains('Unknown field.', $errors['query.x']);
        $this->assertNull($seen);

        $this->assertSame(204, $this->call('POST', '/api/v1/books?draft=true', ['title' => ' Dune '])->statusCode());
        $this->assertSame([['title' => 'Dune'], ['draft' => true]], $seen);
    }

    public function testPoliciesAndProblemsFromHandlers(): void
    {
        $this->cms->api->endpoint($this->endpoint('GET', '/posts/{id}', [
            'capability' => 'read', 'scopes' => ['profile'],
            'authorize' => fn(ApiRequest $r) => $r->param('id') === '7',
            'handle' => fn(ApiRequest $r) => throw ApiProblem::notFound('gone'),
        ]));
        $this->asSession($this->user());

        $this->assertSame(403, $this->call('GET', '/api/v1/posts/8')->statusCode());
        $this->assertSame(['type' => 'urn:kestello:problem:not_found', 'title' => 'Not found', 'status' => 404, 'detail' => 'gone'], $this->json($this->call('GET', '/api/v1/posts/7')));
    }

    public function testCrashesBecomeOpaqueServerErrorsOutsideDebug(): void
    {
        $cms = TestCms::make($this->tempDir(), debug: false);
        $cms->api->endpoint($this->endpoint('GET', '/boom', ['public' => true, 'handle' => fn() => throw new \RuntimeException('secret path /etc/x')]));
        $log = ini_set('error_log', $this->tempDir() . '/php.log');

        $response = $cms->routes->dispatch(new Request('GET', '/api/v1/boom', '/api/v1/boom', [], [], [], [], [], []));

        ini_set('error_log', (string) $log);
        $this->assertSame(500, $response->statusCode());
        $this->assertNotContains('secret', $response->content());
        $this->assertNotNull($response->headers()['X-Request-Id'] ?? null);
    }

    public function testRateLimitPerPrincipal(): void
    {
        $this->cms->api->endpoint($this->endpoint('GET', '/limited', ['public' => true, 'limit' => [2, 60]]));

        $this->assertSame('1', $this->call('GET', '/api/v1/limited')->headers()['RateLimit-Remaining']);
        $this->call('GET', '/api/v1/limited');
        $blocked = $this->call('GET', '/api/v1/limited');

        $this->assertSame(429, $blocked->statusCode());
        $this->assertNotNull($blocked->headers()['Retry-After'] ?? null);

        // A spoofed X-Forwarded-For does not give a fresh bucket.
        $this->assertSame(429, $this->call('GET', '/api/v1/limited', [], ['X-Forwarded-For' => '198.51.100.1'])->statusCode());
    }

    // ── Resources and extensions ────────────────────────────────────

    public function testResourceExtensionsAndResponseFilters(): void
    {
        $resource = new class (['id' => 3, 'secret' => 'x']) extends Resource {
            public const string NAME = 'things';
            protected function fields(Principal $principal): array
            {
                return ['id' => $this->row['id'], 'name' => 'n'];
            }
        };

        ExecutionContext::run('plugin:acme', function (): void {
            $this->cms->api->extend('things')
                ->field('acme_score', fn(array $row) => $row['id'] * 10)
                ->field('name', fn() => 'hijacked')
                ->field('acme_admin_only', fn() => true, read: 'users.manage')
                ->field('acme_broken', fn() => throw new \RuntimeException('nope'));
            $this->cms->filters->add('api.response.things', fn(array $d) => ['only' => 'this']);
        });

        $log = ini_set('error_log', $this->tempDir() . '/php.log');
        $asUser = $resource->resolve($this->user('user'), $this->cms->api);
        $asAdmin = $resource->resolve($this->user('admin'), $this->cms->api);
        ini_set('error_log', (string) $log);

        $this->assertSame(['id' => 3, 'name' => 'n', 'acme_score' => 30], $asUser);
        $this->assertTrue($asAdmin['acme_admin_only']);
    }

    // ── Small parts ─────────────────────────────────────────────────

    public function testSchemaFormatsAndCoercion(): void
    {
        $schema = Schema::object([
            'email' => Schema::string()->format('email'),
            'when' => Schema::string()->format('date-time')->optional(),
            'day' => Schema::string()->format('date')->optional(),
            'n' => Schema::int()->min(1)->optional(),
            'site' => Schema::string()->format('uri')->optional(),
            'note' => Schema::string()->nullable()->optional(),
        ]);

        [, $errors] = $schema->validate(['email' => 'nope', 'when' => '2026-13-01T00:00:00Z', 'day' => '2026-02-31', 'n' => '5', 'site' => 'javascript:alert(1)', 'note' => null]);
        $this->assertSame(['email', 'when', 'day', 'n', 'site'], array_keys($errors));

        [$clean, $errors] = $schema->validate(['email' => 'a@b.test', 'n' => '5'], coerce: true);
        $this->assertSame([], $errors);
        $this->assertSame(5, $clean['n']);

        [, $errors] = Schema::object()->validate(['a', 'b']);
        $this->assertSame(['_'], array_keys($errors));
    }

    public function testCursorRoundTripAndTampering(): void
    {
        $this->assertSame(42, Cursor::decode(Cursor::encode(42)));
        $this->assertNull(Cursor::decode('not-a-cursor'));
        $this->assertNull(Cursor::decode(rtrim(base64_encode('id:1 OR 1=1'), '=')));
    }

    public function testTrustedProxies(): void
    {
        $this->assertSame('10.0.0.5', TrustedProxies::clientIp('10.0.0.5', '1.2.3.4'));

        TrustedProxies::set(['10.0.0.0/8', '2001:db8::/32']);
        $this->assertSame('1.2.3.4', TrustedProxies::clientIp('10.0.0.5', '9.9.9.9, 1.2.3.4'));
        $this->assertSame('1.2.3.4', TrustedProxies::clientIp('2001:db8::1', '1.2.3.4, 10.1.1.1'));
        $this->assertSame('10.0.0.5', TrustedProxies::clientIp('10.0.0.5', 'garbage'));
        $this->assertSame('192.0.2.1', TrustedProxies::clientIp('192.0.2.1', '1.2.3.4'));
        $this->assertFalse(TrustedProxies::matches('11.0.0.1', '10.0.0.0/8'));
    }

    public function testPluginCapabilitiesMustBePrefixed(): void
    {
        ExecutionContext::run('plugin:acme', function (): void {
            $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->auth->roles->capability('users.delete-all', 'x'));
            $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->auth->roles->grant('user', '*'));
            $this->cms->auth->roles->capability('acme.manage', 'Manage', grantTo: ['admin', 'user']);
        });

        $this->assertTrue($this->user('user')->can('acme.manage'));
        $this->assertFalse($this->user('user')->can('users.read'));
        $this->assertTrue($this->user('admin')->can('anything.at.all'));
        $this->assertFalse(Principal::guest()->can('read'));
    }
}
