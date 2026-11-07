<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Testing\TestCase;

final class RouterTest extends TestCase
{
    public static function request(string $method, string $path, array $post = [], array $headers = []): Request
    {
        return new Request($method, $path, $path, $headers, [], $post, [], [], []);
    }

    public function testDispatchesWithParameters(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn(Request $r, string $id) => Response::html("user $id"));

        $this->assertSame('user 42', $router->dispatch(self::request('GET', '/users/42'))->content());
    }

    public function testHeadIsAnsweredByGetRoutesOnly(): void
    {
        $router = new Router();
        $router->get('/page', fn() => Response::html('page'));
        $router->post('/form', fn() => Response::html('posted'));

        $this->assertSame(200, $router->dispatch(self::request('HEAD', '/page'))->statusCode());
        $this->assertSame(404, $router->dispatch(self::request('HEAD', '/form'))->statusCode());
    }

    public function testLiteralPartsOfAPathAreMatchedLiterally(): void
    {
        $router = new Router();
        $router->get('/feed.xml', fn() => Response::html('feed'));
        $router->get('/c++/{slug}', fn(Request $r, string $slug) => Response::html($slug));

        $this->assertSame(200, $router->dispatch(self::request('GET', '/feed.xml'))->statusCode());
        $this->assertSame(404, $router->dispatch(self::request('GET', '/feedXxml'))->statusCode());
        $this->assertSame('intro', $router->dispatch(self::request('GET', '/c++/intro'))->content());
    }

    public function testUnknownRouteIs404(): void
    {
        $this->assertSame(404, (new Router())->dispatch(self::request('GET', '/missing'))->statusCode());
    }

    public function testNestedGroupWithoutPrefixDoesNotDoubleSlash(): void
    {
        // Regression: '/api' + group without prefix produced '/api//user'.
        $router = new Router();
        $router->group(['prefix' => '/api'], function (Router $router): void {
            $router->group(['middleware' => []], function (Router $router): void {
                $router->get('/user', fn() => Response::html('ok'));
            });
        });

        $this->assertSame('/api/user', $router->routes()[0]->path);
        $this->assertSame('ok', $router->dispatch(self::request('GET', '/api/user'))->content());
    }

    public function testGlobalMiddlewareWrapsMatchedAndUnmatchedRequests(): void
    {
        $router = new Router();
        $router->get('/', fn() => Response::html('home'));
        $router->pushGlobalMiddleware(new class implements MiddlewareInterface {
            public function handle(Request $request, Closure $next): Response
            {
                return $next($request)->withHeader('X-Global', 'yes');
            }
        });

        $this->assertSame('yes', $router->dispatch(self::request('GET', '/'))->headers()['X-Global'] ?? null);
        $this->assertSame('yes', $router->dispatch(self::request('GET', '/nope'))->headers()['X-Global'] ?? null);
    }

    public function testNamedRouteUrlGeneration(): void
    {
        $router = new Router();
        $router->get('/admin/users/{id}/edit', fn() => '')->name('admin.users.edit');

        $this->assertSame('/admin/users/7/edit', $router->url('admin.users.edit', ['id' => 7]));
    }
}
