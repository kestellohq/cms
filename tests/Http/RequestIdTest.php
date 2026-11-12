<?php

declare(strict_types=1);

namespace Kestello\Tests\Http;

use Kestello\Cms\Audit\AuditLog;
use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\ErrorHandler;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\RequestId;
use Kestello\Tests\Auth\AuthTestCase;

/** One ID per request, on its response, its log lines and its audit entries. */
final class RequestIdTest extends AuthTestCase
{
    private const string TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    protected function tearDown(): void
    {
        RequestId::end();
        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private static function request(string $path, array $headers = []): Request
    {
        return new Request('GET', $path, (string) parse_url($path, PHP_URL_PATH), $headers, [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '');
    }

    public function testARequestKeepsAnIdThatIsSafeToLog(): void
    {
        foreach (['abcdefgh', 'req-2026.11.12:7_x', str_repeat('a', 128), '0f8fad5b-d9cb-469f-a165-70867728950e'] as $id) {
            $this->assertSame($id, RequestId::of(self::request('/', ['X-Request-Id' => $id])), $id);
        }
        foreach (['short', str_repeat('a', 129), "abcdefgh\n[fake] log line", 'has spaces in it', 'quote"marks', '<script>xx', ''] as $unsafe) {
            $made = RequestId::of(self::request('/', ['X-Request-Id' => $unsafe]));
            $this->assertNotSame($unsafe, $made);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $made, 'a new one instead');
        }
        $this->assertSame('abcdefgh', RequestId::of(self::request('/', ['x-request-id' => 'abcdefgh'])), 'header names are case-insensitive');
    }

    public function testATraceparentGivesItsTraceId(): void
    {
        $this->assertSame(self::TRACE, RequestId::of(self::request('/', ['traceparent' => '00-' . self::TRACE . '-00f067aa0ba902b7-01'])));
        $this->assertSame(self::TRACE, RequestId::fromTraceparent('01-' . strtoupper(self::TRACE) . '-00f067aa0ba902b7-01-future'), 'later versions may add fields');
        $this->assertSame('abcdefgh', RequestId::of(self::request('/', ['X-Request-Id' => 'abcdefgh', 'traceparent' => '00-' . self::TRACE . '-00f067aa0ba902b7-01'])), 'X-Request-Id first');
        foreach ([
            'ff-' . self::TRACE . '-00f067aa0ba902b7-01' => 'version ff',
            '00-' . str_repeat('0', 32) . '-00f067aa0ba902b7-01' => 'an all-zero trace ID',
            '00-' . self::TRACE . '-0000000000000000-01' => 'an all-zero parent ID',
            '00-' . self::TRACE . '-00f067aa0ba902b7-01-extra' => 'version 00 with more fields',
            '00-' . substr(self::TRACE, 1) . '-00f067aa0ba902b7-01' => 'a short trace ID',
            'not a traceparent' => 'garbage',
        ] as $header => $why) {
            $this->assertNull(RequestId::fromTraceparent($header), $why);
        }
    }

    public function testEachRequestGetsANewId(): void
    {
        $this->assertNotSame(RequestId::of(self::request('/')), RequestId::of(self::request('/')));
        $this->assertNull(RequestId::current(), 'none outside a request');
    }

    public function testEveryResponseCarriesIt(): void
    {
        $app = $this->app();
        $app->router()->get('/hello', static fn(): Response => new Response('hi'));
        $app->router()->get('/broken', static fn(): Response => throw new \RuntimeException('down'));

        $ok = $app->handle(self::request('/hello', ['X-Request-Id' => 'from-the-proxy-1']));
        $this->assertSame('from-the-proxy-1', $ok->headers()['X-Request-Id'] ?? null);
        $this->assertSame('from-the-proxy-1', RequestId::current());
        RequestId::end();

        $missing = $app->handle(self::request('/nowhere'));
        $this->assertSame(404, $missing->statusCode());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $missing->headers()['X-Request-Id'] ?? '');
        RequestId::end();

        // the Kernel begins it before booting: handle() keeps that one
        $begun = RequestId::beginFromServer(['HTTP_TRACEPARENT' => '00-' . self::TRACE . '-00f067aa0ba902b7-01']);
        $this->assertSame(self::TRACE, $begun);
        $this->assertSame(self::TRACE, $app->handle(self::request('/hello'))->headers()['X-Request-Id'] ?? null);
        RequestId::end();

        $failed = $app->handle(self::request('/broken', ['Accept' => 'application/json']));
        $id = $failed->headers()['X-Request-Id'] ?? '';
        $this->assertSame(500, $failed->statusCode());
        $this->assertSame($id, json_decode($failed->content(), true)['error']['request_id'] ?? null, 'the error says which request it was');
        $line = json_decode(trim((string) file_get_contents($this->logs . '/' . date('Y-m-d') . '.log')), true);
        $this->assertSame([$id, 'RuntimeException: down'], [$line['request_id'] ?? null, $line['message'] ?? null], 'and so does its log line');
    }

    public function testApiAnswersCarryIt(): void
    {
        $request = new Request('GET', '/api/v1/system/health', '/api/v1/system/health', ['X-Request-Id' => 'api-call-0001'], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '');
        $this->assertSame('api-call-0001', $this->cms->routes->dispatch($request)->headers()['X-Request-Id'] ?? null);

        RequestId::begin(self::request('/', ['X-Request-Id' => 'begun-earlier']));
        $this->assertSame('begun-earlier', $this->cms->routes->dispatch($request)->headers()['X-Request-Id'] ?? null, 'the ID the request began with');
    }

    public function testAuditEntriesCarryIt(): void
    {
        RequestId::begin(self::request('/', ['X-Request-Id' => 'audited-request']));
        $this->cms->audit->record('core.request-id-test');
        RequestId::end();
        $this->cms->audit->record('core.request-id-test');

        $ids = $this->db->table(AuditLog::TABLE)->where('event', 'core.request-id-test')->orderBy('id')->pluck('request_id');
        $this->assertSame(['audited-request', null], array_values($ids));
    }

    private string $logs = '';

    /** An Application of its own, not the singleton other tests use. */
    private function app(): Application
    {
        $root = $this->tempDir();
        mkdir("{$root}/config");
        file_put_contents("{$root}/config/app.php", '<?php return ' . var_export(['debug' => false], true) . ';');
        $class = new \ReflectionClass(Application::class);
        $app = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($app, new Paths($root, dirname(__DIR__, 2) . '/core'));
        $this->logs = "{$root}/logs";
        $logs = $this->logs;
        $app->container()->singleton(ErrorHandler::class, static fn(): ErrorHandler => new ErrorHandler(false, $logs));

        return $app;
    }
}
