<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Registry\Envelope;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Cms\Package\Registry\RegistryException;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Testing\TestCase;

/** The site's copy of the registry: fetched, checked, cached, and never rolled back. */
final class RegistryClientTest extends TestCase
{
    private const string BASE = 'https://registry.example/v1/';

    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $root;
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $index;
    private int $now;
    /** @var array<string, string> path => body served */
    private array $served = [];
    /** @var array<string, mixed> */
    private array $state = [];
    private string $cache;
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = PackageSignature::generateKeys();
        $this->index = PackageSignature::generateKeys();
        $this->now = (int) strtotime('2027-01-15T12:00:00Z');
        $this->cache = $this->tempDir();
        $this->transport = new FakeTransport();
        $this->publish(100);
    }

    private static function at(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** Serve a registry whose index has $serial, built at $at (default: now). */
    private function publish(int $serial, ?int $at = null, string $summary = 'Event listings.'): void
    {
        $at ??= $this->now;
        $seal = static fn(array $payload, string $kind, string $secret): string => (string) json_encode(Envelope::seal((string) json_encode($payload), [$secret], $kind));
        $this->served['keys.json'] = $seal([
            'kind' => 'keys', 'format' => 1, 'serial' => 1, 'issued_at' => self::at($at - 86_400), 'expires_at' => self::at($at + 300 * 86_400),
            'index_keys' => [['key' => $this->index['id'], 'public_key' => $this->index['public_key'], 'valid_from' => self::at($at - 86_400), 'valid_until' => self::at($at + 300 * 86_400)]],
            'revoked' => [],
        ], Envelope::KEY, $this->root['secret_key']);

        $package = (string) json_encode([
            'format' => 1, 'slug' => 'events', 'type' => 'plugin', 'organisation' => 'acme', 'name' => 'Events', 'summary' => $summary,
            'license' => 'GPL-2.0-or-later', 'categories' => ['content'], 'commerce' => null,
            'versions' => [['version' => '1.5.0', 'released_at' => self::at($at - 3600), 'requires' => ['core' => '^0.1'], 'download' => 'dl/events/1.5.0/' . str_repeat('a', 64) . '.zip', 'sha256' => str_repeat('a', 64), 'size' => 100, 'key' => '0123456789abcdef']],
        ]);
        $org = (string) json_encode(['format' => 1, 'id' => 'acme', 'name' => 'Acme', 'keys' => []]);
        $advisories = (string) json_encode(['format' => 1, 'advisories' => []]);
        $ref = static fn(string $path, string $bytes): array => ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        $paths = ['p/events/' . hash('sha256', $package) . '.json' => $package, 'o/acme/' . hash('sha256', $org) . '.json' => $org, 'advisories/' . hash('sha256', $advisories) . '.json' => $advisories];
        $this->served = array_merge($this->served, $paths);
        [$packagePath, $orgPath, $advisoriesPath] = array_keys($paths);

        $this->served['index.json'] = $seal([
            'format' => 1, 'serial' => $serial, 'issued_at' => self::at($at), 'expires_at' => self::at($at + 14 * 86_400),
            'packages' => [[
                'slug' => 'events', 'type' => 'plugin', 'name' => 'Events', 'summary' => $summary, 'organisation' => 'acme', 'categories' => ['content'],
                'latest' => ['stable' => '1.5.0'], 'requires_core' => '^0.1', 'paid' => false, 'updated_at' => self::at($at - 3600), 'file' => $ref($packagePath, $package),
            ]],
            'organisations' => [['id' => 'acme', 'name' => 'Acme', 'file' => $ref($orgPath, $org)]],
            'advisories' => $ref($advisoriesPath, $advisories),
            'moves' => [],
        ], Envelope::INDEX, $this->index['secret_key']);

        foreach ($this->served as $path => $_) {
            $this->transport->on('GET ' . self::BASE . $path, function (PendingRequest $r) use ($path): array {
                $body = $this->served[$path];
                $etag = '"' . md5($body) . '"';

                return ($r->headers['If-None-Match'] ?? null) === $etag ? ['status' => 304] : ['body' => $body, 'headers' => ['ETag' => $etag]];
            });
        }
    }

    /** @param array<string, string>|null $roots */
    private function client(string $base = self::BASE, ?int $now = null, ?array $roots = null): RegistryClient
    {
        $now ??= $this->now;

        return new RegistryClient(
            new HttpClient($this->transport, static fn(string $host): array => ['104.18.1.1']),
            $base,
            $roots ?? [$this->root['id'] => (string) base64_decode($this->root['public_key'], true)],
            $this->cache,
            fn(): array => $this->state,
            function (array $state): void {
                $this->state = $state;
            },
            static fn(): int => $now,
        );
    }

    /** @return list<string> request paths after the base */
    private function requested(): array
    {
        return array_map(static fn(PendingRequest $r): string => substr($r->url, strlen(self::BASE)), $this->transport->sent);
    }

    public function testARefreshFetchesChecksAndKeepsTheRegistry(): void
    {
        $status = $this->client()->refresh();
        $this->assertNull($status['error']);
        $this->assertSame([100, 1, 1], [$status['index_serial'], $status['keys_serial'], $status['packages']]);
        $this->assertSame($this->now + 14 * 86_400, $status['expires_at']);

        // a new client (a new request) reads the kept copy without asking the registry
        $this->transport->sent = [];
        $client = $this->client();
        $this->assertSame('Events', $client->package('events')['name'] ?? null);
        $this->assertSame('Acme', $client->organisation('acme')?->name);
        $this->assertSame([], $client->advisories()->all);
        $this->assertNull($client->package('nothing'));
        $this->assertCount(3, $this->transport->sent, 'only the three pinned files');

        $this->transport->sent = [];
        $this->client()->package('events');
        $this->assertSame([], $this->transport->sent, 'pinned files are kept by hash');
    }

    public function testRequestsSayNothingAboutTheSite(): void
    {
        $this->client()->refresh();
        foreach ($this->transport->sent as $request) {
            $this->assertSame('Kestello', $request->headers['User-Agent'] ?? null);
            $this->assertSame([], array_diff(array_keys($request->headers), ['User-Agent', 'Accept', 'If-None-Match']), $request->url);
            $this->assertStringNotContainsString('?', $request->url);
        }
    }

    public function testAnUnchangedRegistryAnswers304AndTheCopyIsKept(): void
    {
        $this->client()->refresh();
        $this->transport->sent = [];
        $status = $this->client()->refresh();
        $this->assertNull($status['error']);
        $this->assertSame(['keys.json', 'index.json'], $this->requested());
        $this->assertArrayHasKey('If-None-Match', $this->transport->sent[1]->headers);
        $this->assertSame(100, $status['index_serial']);
    }

    public function testAnOlderIndexIsRefusedAndTheNewerCopyStays(): void
    {
        $this->client()->refresh();
        $this->publish(99, summary: 'An older list');
        $status = $this->client()->refresh();
        $this->assertStringContainsString('older than one this site has already seen', (string) $status['error']);
        $this->assertSame(100, $status['index_serial']);
        $this->assertSame('Event listings.', $this->client()->package('events')['summary'] ?? null);
    }

    public function testANewerIndexReplacesTheCopy(): void
    {
        $this->client()->refresh();
        $this->publish(101, $this->now + 3600, 'Event listings, now with tickets.');
        $status = $this->client(now: $this->now + 3600)->refresh();
        $this->assertNull($status['error']);
        $this->assertSame(101, $status['index_serial']);
        $this->assertSame('Event listings, now with tickets.', $this->client(now: $this->now + 3600)->package('events')['summary'] ?? null);
    }

    public function testWhenTheRegistryCantBeReachedTheCopyStaysUntilItExpires(): void
    {
        $this->client()->refresh();
        $this->transport->on('GET ' . self::BASE . 'keys.json', static fn(): array => ['status' => 503]);
        $later = $this->now + 5 * 86_400;
        $status = $this->client(now: $later)->refresh();
        $this->assertSame('The registry answered HTTP 503 for keys.json.', $status['error']);
        $this->assertSame(1, $status['packages'], 'the last good copy is still in use');

        $expired = $this->now + 16 * 86_400;
        $client = $this->client(now: $expired);
        $this->assertNull($client->index());
        $this->assertSame(0, $client->status()['packages']);
        $this->assertThrows(RegistryException::class, static fn() => $client->package('events'));
    }

    public function testAPinnedFileThatDoesntMatchIsNeitherUsedNorKept(): void
    {
        $this->client()->refresh();
        $index = $this->client()->index();
        $path = $index?->packages['events']['file']->path;
        $this->served[(string) $path] = str_replace('Event listings.', 'Evil listings.', $this->served[(string) $path]);
        $this->assertThrows(RegistryException::class, fn() => $this->client()->package('events'));
        $this->assertSame([], glob($this->cache . '/files/*') ?: []);
    }

    public function testABadAddressOrNoRootKeysIsAnErrorNotACrash(): void
    {
        $this->assertSame('registry.url must be an https address ending in "/".', $this->client('http://registry.example/v1/')->refresh()['error']);
        $this->assertSame('registry.url must be an https address ending in "/".', $this->client('https://registry.example/v1')->refresh()['error']);
        $this->assertSame('Kestello has no registry root keys (registry.root_keys).', $this->client(roots: [])->refresh()['error']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testNothingFetchedYetSaysSo(): void
    {
        $status = $this->client()->status();
        $this->assertSame([null, 0, 'The registry has not been fetched yet.'], [$status['checked_at'], $status['packages'], $status['error']]);
    }
}
