<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpBlocked;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpResponse;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Testing\TestCase;

/** Outgoing HTTP: SSRF guard, plugin allowlists, redirects. */
final class HttpClientTest extends TestCase
{
    /** @var array<string, list<string>> fake DNS */
    private array $dns = [
        'api.example.com' => ['93.184.216.34'],
        'other.example.org' => ['93.184.216.35'],
        'rebind.example.com' => ['93.184.216.36', '127.0.0.1'],
        'internal.example.com' => ['10.0.0.5'],
        'metadata.example.com' => ['169.254.169.254'],
        'v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946'],
        'v6local.example.com' => ['::ffff:127.0.0.1'],
    ];

    private function client(FakeTransport $fake, ?array $allow = null): HttpClient
    {
        return new HttpClient(
            $fake,
            fn(string $host): array => $this->dns[$host] ?? [],
            $allow === null ? null : static fn(string $owner): ?array => $allow[$owner] ?? [],
        );
    }

    protected function tearDown(): void
    {
        ExecutionContext::reset();
    }

    public function testRequestShapeAndPinnedAddress(): void
    {
        $fake = new FakeTransport(['POST https://api.example.com/token' => ['json' => ['ok' => true]]]);
        $res = $this->client($fake)->post('https://api.example.com/token', ['form' => ['a' => 'b c'], 'bearer' => 'T', 'query' => ['x' => '1']]);

        $this->assertSame(['ok' => true], $res->json());
        $sent = $fake->sent[0];
        $this->assertSame('https://api.example.com/token?x=1', $sent->url);
        $this->assertSame('93.184.216.34', $sent->ip, 'connection pinned to the vetted address');
        $this->assertSame('a=b+c', $sent->body);
        $this->assertSame('application/x-www-form-urlencoded', $sent->headers['Content-Type']);
        $this->assertSame('Bearer T', $sent->headers['Authorization']);
        $this->assertSame(HttpClient::USER_AGENT, $sent->headers['User-Agent']);
    }

    public function testPrivateAndSpecialAddressesAreRefused(): void
    {
        $client = $this->client(new FakeTransport());
        foreach ([
            'http://127.0.0.1/', 'http://localhost.example/', 'http://10.1.2.3/', 'http://172.16.0.1/', 'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/', 'http://100.64.0.1/', 'http://0.0.0.0/', 'http://[::1]/',
            'http://[::ffff:127.0.0.1]/', 'http://[fd00::1]/', 'http://[fe80::1]/',
            'https://internal.example.com/', 'https://metadata.example.com/', 'https://rebind.example.com/', 'https://v6local.example.com/',
        ] as $url) {
            $this->assertThrows(\Kestello\Framework\Http\Client\HttpException::class, fn() => $client->get($url), $url);
        }
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '::1', 'fc00::1', '::ffff:10.0.0.1', '64:ff9b::7f00:1', 'garbage'] as $ip) {
            $this->assertTrue(HttpClient::isPrivate($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700::1111'] as $ip) {
            $this->assertFalse(HttpClient::isPrivate($ip), $ip);
        }
    }

    public function testSchemesPortsAndCredentialsInUrls(): void
    {
        $client = $this->client(new FakeTransport(['GET https://v6.example.com/' => ['body' => 'ok']]));
        foreach (['file:///etc/passwd', 'gopher://api.example.com/', 'ftp://api.example.com/', 'https://api.example.com:6379/', 'https://user:pw@api.example.com/', '//api.example.com/', 'not a url'] as $url) {
            $this->assertThrows(HttpBlocked::class, fn() => $client->get($url), $url);
        }
        $this->assertSame('ok', $client->get('https://v6.example.com/')->body, 'public IPv6 is fine');
        $this->assertThrows(HttpBlocked::class, fn() => $client->get('https://api.example.com/', ['headers' => ['X-A' => "1\r\nHost: evil"]]), 'header injection');
    }

    public function testPluginsOnlyReachDeclaredHosts(): void
    {
        $fake = new FakeTransport(['* https://api.example.com/' => ['body' => 'ok'], '* https://sub.api.example.com/' => ['body' => 'ok']]);
        $this->dns['sub.api.example.com'] = ['93.184.216.40'];
        $client = $this->client($fake, ['plugin:good' => ['api.example.com', '*.api.example.com'], 'plugin:quiet' => []]);

        $this->assertSame('ok', $client->get('https://api.example.com/')->body, 'core is unrestricted');
        $this->assertSame('ok', ExecutionContext::run('plugin:good', fn() => $client->get('https://api.example.com/')->body));
        $this->assertSame('ok', ExecutionContext::run('plugin:good', fn() => $client->get('https://sub.api.example.com/')->body), 'wildcard subdomain');
        $e = $this->assertThrows(HttpBlocked::class, fn() => ExecutionContext::run('plugin:good', fn() => $client->get('https://other.example.org/')));
        $this->assertContains('permissions.network', $e->getMessage());
        $this->assertThrows(HttpBlocked::class, fn() => ExecutionContext::run('plugin:quiet', fn() => $client->get('https://api.example.com/')), 'nothing declared');
        $this->assertThrows(HttpBlocked::class, fn() => ExecutionContext::run('plugin:good', fn() => $client->get('https://api.example.com/', ['allow_private' => true])), 'allow_private is core-only');

        $this->assertFalse(HttpClient::hostAllowed('evilapi.example.com', ['*.api.example.com']));
        $this->assertFalse(HttpClient::hostAllowed('api.example.com', ['*.api.example.com']), 'wildcard is subdomains only');
    }

    public function testRedirectsAreRevettedAndStripCredentials(): void
    {
        $fake = new FakeTransport([
            'GET https://api.example.com/a' => ['status' => 302, 'headers' => ['Location' => '/b']],
            'GET https://api.example.com/b' => ['status' => 301, 'headers' => ['Location' => 'https://other.example.org/c']],
            'GET https://other.example.org/c' => ['body' => 'done'],
            'GET https://api.example.com/meta' => ['status' => 302, 'headers' => ['Location' => 'http://169.254.169.254/']],
            'GET https://api.example.com/down' => ['status' => 302, 'headers' => ['Location' => 'http://api.example.com/']],
            'POST https://api.example.com/post' => ['status' => 303, 'headers' => ['Location' => '/b']],
        ]);
        $client = $this->client($fake);

        $this->assertSame('done', $client->get('https://api.example.com/a', ['bearer' => 'secret'])->body);
        $this->assertSame('Bearer secret', $fake->sent[1]->headers['Authorization'], 'same host keeps the token');
        $this->assertFalse(isset($fake->sent[2]->headers['Authorization']), 'token never sent to another host');

        $this->assertThrows(HttpBlocked::class, fn() => $client->get('https://api.example.com/meta'), 'redirect into metadata service');
        $this->assertThrows(HttpBlocked::class, fn() => $client->get('https://api.example.com/down'), 'https → http downgrade');
        $this->assertSame(302, $client->get('https://api.example.com/a', ['max_redirects' => 0])->status);

        $fake->sent = [];
        $client->post('https://api.example.com/post', ['json' => ['x' => 1]]);
        $this->assertSame('GET', $fake->sent[1]->method, '303 turns POST into GET');
        $this->assertNull($fake->sent[1]->body);
    }

    public function testFakeTransportClosuresAndJsonErrors(): void
    {
        $fake = (new FakeTransport())->on('GET https://api.example.com/x', fn(PendingRequest $r) => new HttpResponse(500, [], 'oops', $r->url));
        $res = $this->client($fake)->get('https://api.example.com/x');
        $this->assertFalse($res->ok());
        $this->assertThrows(\Kestello\Framework\Http\Client\HttpException::class, fn() => $res->throw());
        $this->assertThrows(\Kestello\Framework\Http\Client\HttpException::class, fn() => $res->json());
    }

    public function testCoreWorkInsidePluginCodeRunsAsCore(): void
    {
        $seen = \Kestello\Framework\Core\ExecutionContext::run('plugin:acme', fn() => [
            \Kestello\Framework\Core\ExecutionContext::current(),
            \Kestello\Framework\Core\ExecutionContext::run('core', fn() => \Kestello\Framework\Core\ExecutionContext::current()),
            \Kestello\Framework\Core\ExecutionContext::current(),
        ]);
        $this->assertSame(['plugin:acme', 'core', 'plugin:acme'], $seen);
    }
}
