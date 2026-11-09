<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Cms\Package\Update\PackageUpdates;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Security\AppKeySecretStore;
use Kestello\Framework\Testing\TestCase;
use RuntimeException;

/** Checking plugin and theme sources: GitHub releases, when checks are due, and downloads. */
final class PackageUpdatesTest extends TestCase
{
    private const string BASE = 'https://github.com/acme/events/releases/download/';

    private FakeTransport $transport;
    /** @var array<string, array<string, mixed>> */
    private array $saved = [];
    private int $now = 1_800_000_000;
    private string $installed = '1.0.0';
    private string $tmp;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->saved = [];
        $this->installed = '1.0.0';
        $this->tmp = $this->tempDir() . '/updates';
    }

    private function updates(): PackageUpdates
    {
        $licenses = new PackageLicenses(new AppKeySecretStore('base64:' . base64_encode(str_repeat('k', 32))), static fn(): ?string => null, static function (): void {});

        return new PackageUpdates(
            new HttpClient($this->transport, static fn(): array => ['140.82.112.3']),
            $licenses,
            fn(): array => [
                ['owner' => 'plugin:events', 'type' => 'plugin', 'slug' => 'events', 'name' => 'Events', 'version' => $this->installed, 'updates' => ['github' => 'acme/events']],
                ['owner' => 'plugin:plain', 'type' => 'plugin', 'slug' => 'plain', 'name' => 'Plain', 'version' => '1.0.0', 'updates' => null],
            ],
            '0.1.0',
            '8.4.0',
            'https://client.example',
            ['check_every' => 12],
            $this->tmp,
            fn(): array => $this->saved,
            function (array $r): void {
                $this->saved = $r;
            },
            fn(): int => $this->now,
        );
    }

    /** @param list<string> $versions */
    private function releases(array $versions): void
    {
        $this->transport->on('GET https://api.github.com/repos/acme/events/releases', ['json' => array_map(static function (string $v): array {
            $base = self::BASE . "v{$v}/";

            return ReleaseFeedTest::release($v, ['name' => "Events {$v}", 'assets' => [
                ['name' => "events-{$v}.zip", 'browser_download_url' => $base . "events-{$v}.zip"],
                ['name' => "events-{$v}.zip.sha256", 'browser_download_url' => $base . "events-{$v}.zip.sha256"],
            ]], 'acme/events');
        }, $versions)]);
    }

    public function testGitHubReleasesOfAPackageAreOffered(): void
    {
        $this->releases(['1.1.0', '1.0.0']);
        $results = $this->updates()->check();

        $this->assertSame(['plugin:events'], array_keys($results), 'a package without a source is not asked');
        $this->assertSame(['1.1.0', 'github', null], [$results['plugin:events']['offer']['version'] ?? null, $results['plugin:events']['source'], $results['plugin:events']['error']]);
        $this->assertSame(1, $this->updates()->waiting());

        $this->installed = '1.1.0';
        $this->assertNull($this->updates()->results()['plugin:events']['offer'], 'updated since: the old answer offers nothing');
    }

    public function testScheduledChecksWaitTheirTurn(): void
    {
        $this->releases(['1.1.0']);
        $this->updates()->check(dueOnly: true);
        $this->updates()->check(dueOnly: true);
        $this->assertCount(1, $this->transport->sent);

        $this->now += 12 * 3600;
        $this->updates()->check(dueOnly: true);
        $this->assertCount(2, $this->transport->sent);

        $this->installed = '1.1.0';
        $this->updates()->check(dueOnly: true);
        $this->assertCount(3, $this->transport->sent, 'a new version installed is checked at once');
    }

    public function testAFailingSourceIsRememberedWithItsReason(): void
    {
        $this->transport->on('GET https://api.github.com/repos/acme/events/releases', ['status' => 429]);
        $this->assertStringContainsString('limiting requests', (string) $this->updates()->check()['plugin:events']['error']);
    }

    public function testADownloadIsCheckedAgainstItsChecksum(): void
    {
        $this->releases(['1.1.0']);
        $this->transport->on('GET ' . self::BASE . 'v1.1.0/events-1.1.0.zip.sha256', ['body' => hash('sha256', 'the zip') . "  events-1.1.0.zip\n"]);
        $this->transport->on('GET ' . self::BASE . 'v1.1.0/events-1.1.0.zip', ['body' => 'the zip']);

        $download = $this->updates()->download('plugin:events');
        $this->assertSame(['1.1.0', 'the zip'], [$download['version'], file_get_contents($download['path'])]);
        unlink($download['path']);

        $this->transport->on('GET ' . self::BASE . 'v1.1.0/events-1.1.0.zip', ['body' => 'something else']);
        try {
            $this->updates()->download('plugin:events');
            $this->fail('a download that does not match its checksum was kept');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not match its published checksum', $e->getMessage());
        }
        $this->assertSame([], glob($this->tmp . '/*') ?: [], 'nothing is left behind');

        $this->installed = '1.1.0';
        try {
            $this->updates()->download('plugin:events');
            $this->fail('downloaded the version already installed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('There is no update for Events 1.1.0', $e->getMessage());
        }
    }
}
