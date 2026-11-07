<?php

declare(strict_types=1);

namespace Kestello\Tests\Http;

use InvalidArgumentException;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Platform\DriverDefinition;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Cms\Platform\Subsystem;
use Kestello\Contracts\Search\Engine;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpBlocked;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\ServiceClient;
use Kestello\Framework\Testing\TestCase;

/** The locked-origin client drivers use to reach an administrator's private service, and how drivers get it. */
final class ServiceClientTest extends TestCase
{
    private FakeTransport $fake;
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->fake = new FakeTransport();
        $this->http = new HttpClient($this->fake, static fn(string $host) => ['10.0.0.5'], static fn(string $owner) => []);
    }

    public function testTheServiceClientOnlyTalksToItsConfiguredAddress(): void
    {
        $this->fake->on('GET http://search.internal:7700/health', ['json' => ['status' => 'available']]);
        $this->fake->on('GET http://search.internal:7700/moved', ['status' => 302, 'headers' => ['Location' => 'http://169.254.169.254/latest']]);
        $client = new ServiceClient($this->http, 'http://search.internal:7700/');

        // a plugin can't reach a private host or another port on its own...
        ExecutionContext::run('plugin:meilisearch', function () use ($client): void {
            $this->assertThrows(HttpBlocked::class, fn() => $this->http->get('http://search.internal:7700/health'));
            $this->assertThrows(HttpBlocked::class, fn() => $this->http->get('http://search.internal:7700/health', ['allow_private' => true]));
            // ...but can reach the address the administrator configured
            $this->assertSame(200, $client->get('/health')->status);
            $this->assertSame(302, $client->get('/moved', ['max_redirects' => 5])->status, 'redirects are never followed');
            $this->assertThrows(HttpBlocked::class, fn() => $this->http->get('http://example.test/'), 'and the exception does not leak to other calls');
        });
        foreach (['health', '//evil.test/x', '/../admin', "/a\nb", '/a b', '/a\\b', '/a#b'] as $path) {
            $this->assertThrows(HttpBlocked::class, fn() => $client->get($path), "path {$path}");
        }
        foreach (['ftp://x', 'http://user:pw@x', 'http://x/?a=1', 'search.internal', 'http://x/#f'] as $url) {
            $this->assertThrows(InvalidArgumentException::class, fn() => new ServiceClient($this->http, $url), $url);
        }
        $this->assertSame('http://search.internal:7700', $client->baseUrl());
    }

    public function testDriversGetAClientForTheirEndpointAndPluginsCannotClaimCoreNames(): void
    {
        $container = new Container();
        $container->instance(HttpClient::class, $this->http);
        $registry = new DriverRegistry($container);
        $registry->subsystem(new Subsystem('search', 'Search', Engine::class, default: 'database', fallback: 'database'));
        $seen = null;
        $def = static function (string $name) use (&$seen): DriverDefinition {
            return new DriverDefinition(
                name: $name,
                label: $name,
                schema: Schema::object(['url' => Schema::string(), 'key' => Schema::string()->optional()]),
                factory: static function (array $o) use (&$seen) {
                    $seen = $o;
                    return new \Kestello\Cms\Search\DatabaseEngine();
                },
                secrets: ['key'],
                endpoints: ['url'],
            );
        };

        ExecutionContext::run('plugin:acme', function () use ($registry, $def): void {
            $this->assertThrows(InvalidArgumentException::class, fn() => $registry->register('search', $def('database'), owner: 'core'), 'the owner comes from who is running');
            $registry->register('search', $def('acme'));
        });
        $this->assertSame('acme', $registry->owner('search', 'acme'));
        $registry->make('search', 'acme', ['url' => 'http://search.internal:7700', 'clients' => ['url' => 'forged']]);
        $this->assertInstanceOf(ServiceClient::class, $seen['clients']['url']);
        $this->assertSame('http://search.internal:7700', $seen['clients']['url']->baseUrl());
        $this->assertThrows(InvalidArgumentException::class, static fn() => new DriverDefinition(name: 'x', label: 'x', schema: Schema::object([]), factory: static fn() => null, endpoints: ['url']));
    }
}
