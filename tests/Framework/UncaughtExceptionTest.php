<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\ErrorHandler;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Testing\TestCase;

/**
 * A page that throws is answered like a page that returns a 500: the site's
 * branded error page with its security headers, or, when that can't be drawn,
 * the plain page with its own; JSON for the API; the debug page in debug.
 */
final class UncaughtExceptionTest extends TestCase
{
    private static function request(string $path, string $accept = 'text/html'): Request
    {
        return new Request('GET', $path, $path, ['Accept' => $accept], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '');
    }

    /** An Application of its own, not the singleton other tests use. */
    private function app(bool $debug = false, ?\Closure $branded = null): Application
    {
        $root = $this->tempDir();
        mkdir("{$root}/config");
        file_put_contents("{$root}/config/app.php", '<?php return ' . var_export(['debug' => $debug], true) . ';');
        $class = new \ReflectionClass(Application::class);
        $app = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($app, new Paths($root, dirname(__DIR__, 2) . '/core'));
        $app->container()->singleton(ErrorHandler::class, fn(): ErrorHandler => new ErrorHandler($debug, "{$root}/logs"));
        if ($branded !== null) {
            $app->container()->instance('kestello.error_page', $branded);
        }

        return $app;
    }

    public function testThePlainPageHasItsOwnSecurityHeaders(): void
    {
        $response = (new ErrorHandler())->response(new \RuntimeException('secret detail'), self::request('/about'));
        $this->assertSame(500, $response->statusCode());
        $headers = $response->headers();
        $this->assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy'] ?? '');
        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        $this->assertSame('no-store', $headers['Cache-Control'] ?? null);
        $this->assertFalse(str_contains($response->content(), 'secret detail'), 'the message is not shown to visitors');
    }

    public function testTheStatusComesFromTheException(): void
    {
        $notFound = new class ('gone') extends \RuntimeException {
            public function getStatusCode(): int
            {
                return 404;
            }
        };
        $this->assertSame(404, (new ErrorHandler())->response($notFound, self::request('/x'))->statusCode());
        $this->assertSame(400, (new ErrorHandler())->response(new \InvalidArgumentException('bad'), self::request('/x'))->statusCode());
    }

    public function testTheApiGetsJson(): void
    {
        foreach ([self::request('/api/v1/things'), self::request('/anything', 'application/json')] as $request) {
            $response = (new ErrorHandler())->response(new \RuntimeException('secret detail'), $request);
            $this->assertSame(500, $response->statusCode());
            $this->assertSame(['error' => ['status' => 500, 'message' => 'Internal Server Error']], json_decode($response->content(), true));
            $this->assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null);
        }
    }

    public function testTheErrorIsLogged(): void
    {
        $logs = $this->tempDir();
        (new ErrorHandler(false, $logs))->response(new \RuntimeException('written down'), self::request('/x'));
        $this->assertStringContainsString('RuntimeException: written down', (string) file_get_contents("{$logs}/" . date('Y-m-d') . '.log'));
    }

    public function testThePageIsTheSitesBrandedErrorPage(): void
    {
        $seen = null;
        $app = $this->app(branded: function (Request $request, Response $response) use (&$seen): Response {
            $seen = $response;

            return new Response('<p>branded</p>', $response->statusCode(), ['Content-Type' => 'text/html', 'Content-Security-Policy' => "script-src 'nonce-x'"]);
        });
        $response = $app->failed(self::request('/about'), new \RuntimeException('boom'));
        $this->assertSame('<p>branded</p>', $response->content());
        $this->assertSame(500, $response->statusCode());
        $this->assertSame("script-src 'nonce-x'", $response->headers()['Content-Security-Policy'], 'the site\'s policy, not the plain page\'s');
        $this->assertSame([], $seen?->headers(), 'the renderer gets the status alone');
    }

    public function testWithoutABrandedPageThePlainOneIsSent(): void
    {
        $plain = $this->app()->failed(self::request('/about'), new \RuntimeException('boom'));
        $this->assertSame(500, $plain->statusCode());
        $this->assertStringContainsString("default-src 'none'", $plain->headers()['Content-Security-Policy']);

        // a branded page that fails itself falls back to the plain one
        $broken = $this->app(branded: static fn() => throw new \LogicException('the theme is broken too'));
        $this->assertStringContainsString("default-src 'none'", $broken->failed(self::request('/about'), new \RuntimeException('boom'))->headers()['Content-Security-Policy']);
    }

    public function testTheApiAndDebugAreNotBranded(): void
    {
        $branded = static fn(Request $r, Response $res): Response => new Response('<p>branded</p>', $res->statusCode(), ['Content-Type' => 'text/html']);
        $api = $this->app(branded: $branded)->failed(self::request('/api/v1/x'), new \RuntimeException('boom'));
        $this->assertSame('application/json; charset=UTF-8', $api->headers()['Content-Type']);

        $debug = $this->app(debug: true, branded: $branded)->failed(self::request('/about'), new \RuntimeException('shown in debug'));
        $this->assertStringContainsString('shown in debug', $debug->content());
    }
}
