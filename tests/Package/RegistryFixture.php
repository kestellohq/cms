<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Registry\Envelope;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Support\Zip\Zip;

/**
 * A whole registry for tests, signed as the real one is and served through a FakeTransport:
 * root and index keys, keys.json, organisations with root-certified keys, packages and their
 * zips, advisories and moves. Change it, call publish(), and the next refresh() sees it.
 *
 *   $r = new RegistryFixture();
 *   $acme = $r->organisation('acme', 'Acme');               the organisation's signing key
 *   $r->version('plugin', 'ledger', 'acme', $zip, '1.0.0');  a zip signed with $acme
 *   $r->publish();
 *   $client = $r->client($cacheDir);
 */
final class RegistryFixture
{
    public const string BASE = 'https://registry.example/v1/';

    /** @var array{id: string, public_key: string, secret_key: string} */
    public array $root;
    /** @var array{id: string, public_key: string, secret_key: string} */
    public array $indexKey;
    public FakeTransport $transport;
    public int $now;
    private int $serial = 1000;
    /** @var array<string, string> path => body served */
    public array $served = [];
    /** @var array<string, array{name: string, certificates: list<array<string, mixed>>}> */
    private array $organisations = [];
    /** @var array<string, array{type: string, organisation: string, name: string, versions: list<array<string, mixed>>}> */
    private array $packages = [];
    /** @var list<array<string, mixed>> */
    public array $advisories = [];
    /** @var list<array<string, mixed>> move envelopes */
    private array $moves = [];
    /** @var array<string, string> key id => reason */
    public array $revoked = [];
    /** @var array<string, mixed> the client's stored state */
    public array $state = [];

    public function __construct()
    {
        $this->root = PackageSignature::generateKeys();
        $this->indexKey = PackageSignature::generateKeys();
        $this->transport = new FakeTransport();
        $this->now = time();
    }

    public static function at(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /**
     * Add an organisation with a new key, certified from a year ago for $years, and return the key.
     *
     * @return array{id: string, public_key: string, secret_key: string}
     */
    public function organisation(string $id, string $name, ?int $from = null, ?int $until = null): array
    {
        $key = PackageSignature::generateKeys();
        $this->organisations[$id] ??= ['name' => $name, 'certificates' => []];
        $this->certify($id, $key, $from ?? $this->now - 365 * 86_400, $until ?? $this->now + 365 * 86_400);

        return $key;
    }

    /** @param array{id: string, public_key: string} $key */
    public function certify(string $organisation, array $key, int $from, int $until): void
    {
        $this->organisations[$organisation]['certificates'][] = Envelope::seal((string) json_encode([
            'kind' => 'organisation', 'organisation' => $organisation, 'key' => $key['id'], 'public_key' => $key['public_key'],
            'valid_from' => self::at($from), 'valid_until' => self::at($until),
        ]), [$this->root['secret_key']], Envelope::KEY);
    }

    /**
     * List $zip as $version of the package (it is served under dl/). $overrides change the
     * version's entry (key, withdrawn, released_at, version, …).
     *
     * @param array<string, mixed> $overrides
     */
    public function version(string $type, string $slug, string $organisation, string $zip, string $version, array $overrides = [], ?string $keyId = null): void
    {
        $bytes = (string) file_get_contents($zip);
        $sha = hash('sha256', $bytes);
        $reader = Zip::open($zip);
        $signature = $reader->has(PackageSignature::FILE) ? json_decode($reader->read(PackageSignature::FILE, 8192), true) : null;
        $path = "dl/{$slug}/{$version}/{$sha}.zip";
        $this->served[$path] = $bytes;
        $this->packages[$slug] ??= ['type' => $type, 'organisation' => $organisation, 'name' => ucfirst($slug), 'versions' => []];
        $this->packages[$slug]['organisation'] = $organisation;
        $this->packages[$slug]['versions'][] = $overrides + [
            'version' => $version, 'released_at' => self::at($this->now - 3600), 'requires' => ['core' => '>=0.1.0-0'],
            'download' => $path, 'sha256' => $sha, 'size' => strlen($bytes),
            'key' => $keyId ?? (is_array($signature) ? $signature['key'] : '0000000000000000'),
        ];
    }

    /**
     * Change what the registry says about a listed package: name, summary, description, categories,
     * paid, and its icon, screenshots and homepage.
     *
     * @param array{name?: string, summary?: string, description?: string, categories?: list<string>, paid?: bool, icon?: string, screenshots?: list<array{image: string, caption: string}>, homepage?: string} $meta
     */
    public function describe(string $slug, array $meta): void
    {
        $this->packages[$slug]['meta'] = $meta + ($this->packages[$slug]['meta'] ?? []);
    }

    /** Change a listed version (withdraw it, …). */
    public function amend(string $slug, string $version, array $changes): void
    {
        foreach ($this->packages[$slug]['versions'] as &$v) {
            if ($v['version'] === $version) {
                $v = $changes + $v;
            }
        }
    }

    public function move(string $slug, string $from, string $to): void
    {
        $this->moves[] = Envelope::seal((string) json_encode(['kind' => 'move', 'slug' => $slug, 'from' => $from, 'to' => $to, 'issued_at' => self::at($this->now - 60)]), [$this->root['secret_key']], Envelope::KEY);
        $this->packages[$slug]['organisation'] = $to;
    }

    /** Build and serve keys.json, the index and every file it pins, with a new serial. */
    public function publish(): void
    {
        $this->serial++;
        $seal = static fn(array $payload, string $kind, string $secret): string => (string) json_encode(Envelope::seal((string) json_encode($payload), [$secret], $kind));
        $this->served['keys.json'] = $seal([
            'kind' => 'keys', 'format' => 1, 'serial' => $this->serial, 'issued_at' => self::at($this->now - 86_400), 'expires_at' => self::at($this->now + 300 * 86_400),
            'index_keys' => [['key' => $this->indexKey['id'], 'public_key' => $this->indexKey['public_key'], 'valid_from' => self::at($this->now - 86_400), 'valid_until' => self::at($this->now + 300 * 86_400)]],
            'revoked' => array_map(fn(string $id, string $reason): array => ['key' => $id, 'at' => self::at($this->now - 60), 'reason' => $reason], array_keys($this->revoked), array_values($this->revoked)),
        ], Envelope::KEY, $this->root['secret_key']);

        $pin = function (string $dir, string $bytes): array {
            $path = "{$dir}/" . hash('sha256', $bytes) . '.json';
            $this->served[$path] = $bytes;

            return ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        };
        $packages = [];
        foreach ($this->packages as $slug => $p) {
            $meta = ($p['meta'] ?? []) + ['name' => $p['name'], 'summary' => "{$p['name']} for tests.", 'description' => '', 'categories' => [], 'paid' => false];
            $commerce = $meta['paid'] ? ['model' => 'yearly-per-site', 'price' => 'From 49 EUR a year', 'store_url' => 'https://acme.example/buy', 'license' => 'seller'] : null;
            $file = (string) json_encode(['format' => 1, 'slug' => $slug, 'type' => $p['type'], 'organisation' => $p['organisation'], 'name' => $meta['name'], 'summary' => $meta['summary'], 'description' => $meta['description'], 'license' => 'GPL-2.0-or-later', 'categories' => $meta['categories'], 'commerce' => $commerce, 'versions' => $p['versions']] + array_intersect_key($meta, ['icon' => 1, 'screenshots' => 1, 'homepage' => 1]));
            $stable = array_values(array_filter(array_column($p['versions'], 'version'), static fn(string $v): bool => !str_contains($v, '-')));
            usort($stable, 'version_compare');
            // as the builder does: what the newest stable version needs
            $latest = array_values(array_filter($p['versions'], static fn(array $v): bool => $v['version'] === (end($stable) ?: null)))[0] ?? [];
            $packages[] = [
                'slug' => $slug, 'type' => $p['type'], 'name' => $meta['name'], 'summary' => $meta['summary'], 'organisation' => $p['organisation'], 'categories' => $meta['categories'],
                'latest' => ['stable' => end($stable) ?: '0.0.1'], 'paid' => $meta['paid'], 'requires_core' => $latest['requires']['core'] ?? null, 'updated_at' => self::at($this->now - 3600), 'file' => $pin("p/{$slug}", $file),
            ] + array_intersect_key($meta, ['icon' => 1]);
        }
        $organisations = [];
        foreach ($this->organisations as $id => $o) {
            $organisations[] = ['id' => $id, 'name' => $o['name'], 'file' => $pin("o/{$id}", (string) json_encode(['format' => 1, 'id' => $id, 'name' => $o['name'], 'keys' => $o['certificates']]))];
        }
        $this->served['index.json'] = $seal([
            'format' => 1, 'serial' => $this->serial, 'issued_at' => self::at($this->now), 'expires_at' => self::at($this->now + 14 * 86_400),
            'packages' => $packages, 'organisations' => $organisations,
            'advisories' => $pin('advisories', (string) json_encode(['format' => 1, 'advisories' => $this->advisories])),
            'moves' => $this->moves,
        ], Envelope::INDEX, $this->indexKey['secret_key']);

        foreach (array_keys($this->served) as $path) {
            $this->transport->on('GET ' . self::BASE . $path, function (PendingRequest $r) use ($path): array {
                $body = $this->served[$path];
                $etag = '"' . md5($body) . '"';

                return ($r->headers['If-None-Match'] ?? null) === $etag ? ['status' => 304] : ['body' => $body, 'headers' => ['ETag' => $etag]];
            });
        }
    }

    /** A client of this registry, its cache in $cacheDir, its state kept here. */
    public function client(string $cacheDir): RegistryClient
    {
        return new RegistryClient(
            new HttpClient($this->transport, static fn(string $host): array => ['104.18.1.1']),
            self::BASE,
            [$this->root['id'] => (string) base64_decode($this->root['public_key'], true)],
            $cacheDir,
            fn(): array => $this->state,
            function (array $state): void {
                $this->state = $state;
            },
            fn(): int => $this->now,
        );
    }

    /** @return list<string> the paths asked for, after the base */
    public function requested(): array
    {
        return array_map(static fn(PendingRequest $r): string => substr($r->url, strlen(self::BASE)), $this->transport->sent);
    }
}
