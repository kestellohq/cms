<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Security\Csrf;
use Kestello\Framework\Security\CsrfMiddleware;
use Kestello\Framework\Session\Session;
use Kestello\Framework\Testing\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->middleware = new CsrfMiddleware(new Csrf(new Session()));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function run(Request $request): int
    {
        return $this->middleware->handle($request, fn() => Response::html('passed'))->statusCode();
    }

    public function testGetRequestsAreNotChecked(): void
    {
        $this->assertSame(200, $this->run(RouterTest::request('GET', '/admin')));
    }

    public function testPostWithoutTokenIsRejectedEvenWhenSessionHasNoToken(): void
    {
        // Regression: hash_equals('', '') is true, so an empty session token
        // used to let token-less POSTs through.
        $this->assertSame(403, $this->run(RouterTest::request('POST', '/admin/users/create')));
    }

    public function testPostWithWrongTokenIsRejected(): void
    {
        (new Session())->token();

        $this->assertSame(403, $this->run(RouterTest::request('POST', '/login', ['_csrf_token' => 'forged'])));
    }

    public function testPostWithValidTokenPasses(): void
    {
        $token = (new Session())->token();

        $this->assertSame(200, $this->run(RouterTest::request('POST', '/login', ['_csrf_token' => $token])));
        $this->assertSame(200, $this->run(RouterTest::request('POST', '/login', [], ['X-CSRF-TOKEN' => $token])));
    }

    public function testApiRoutesAreExempt(): void
    {
        $this->assertSame(200, $this->run(RouterTest::request('POST', '/api/v1/things')));
    }

    public function testOnlyTheApiPrefixAndExactExclusionsAreExempt(): void
    {
        $this->middleware = new CsrfMiddleware(new Csrf(new Session()), ['/system/cron'], '/api/v1/');
        $this->assertSame(200, $this->run(RouterTest::request('POST', '/api/v1/things')));
        $this->assertSame(403, $this->run(RouterTest::request('POST', '/api/acme/x')), 'a plugin route under /api/ is not the API');
        $this->assertSame(200, $this->run(RouterTest::request('POST', '/system/cron')));
        $this->assertSame(403, $this->run(RouterTest::request('POST', '/system/cron-anything')));
    }
}
