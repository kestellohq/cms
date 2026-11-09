<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\ManifestException;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Update\KeyPins;
use Kestello\Cms\Package\Update\PackageFeed;
use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Framework\Security\AppKeySecretStore;
use Kestello\Framework\Testing\TestCase;
use UnexpectedValueException;

/** Where plugin and theme updates come from: the manifest, a vendor's feed, license keys and the publisher's key. */
final class PackageFeedTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private static function manifest(array $extra): Manifest
    {
        return Manifest::fromArray(['slug' => 'events', 'name' => 'Events', 'version' => '1.0.0', 'requires' => ['core' => '^0.1']] + $extra, PackageType::Plugin);
    }

    /** @param array<string, mixed> $release */
    public static function release(string $version, array $release = []): array
    {
        return $release + ['version' => $version, 'download' => "https://cdn.acme.example/events-{$version}.zip?sig=x", 'sha256' => str_repeat('ab', 32), 'requires' => ['core' => '^0.1'], 'notes' => "Notes for {$version}"];
    }

    /** @param list<array<string, mixed>> $releases */
    public static function feed(array $releases, string $slug = 'events', array $extra = []): string
    {
        return (string) json_encode($extra + ['format' => 1, 'type' => 'plugin', 'slug' => $slug, 'releases' => $releases]);
    }

    public function testTheManifestNamesOneSource(): void
    {
        $this->assertSame(['github' => 'acme/events'], self::manifest(['updates' => ['github' => 'acme/events']])->updates);
        $this->assertSame(['url' => 'https://updates.acme.example/events.json', 'license' => true], self::manifest(['updates' => ['url' => 'https://updates.acme.example/events.json', 'license' => true]])->updates);
        $this->assertSame(['url' => 'https://updates.acme.example/e.json', 'license' => false], self::manifest(['updates' => ['url' => 'https://updates.acme.example/e.json']])->updates);
        $this->assertNull(self::manifest([])->updates, 'no source: updates by upload only');
        $this->assertSame(['0123456789abcdef'], self::manifest(['signing_keys' => ['0123456789abcdef']])->signingKeys);

        foreach ([
            ['updates' => 'acme/events'],
            ['updates' => ['github' => 'acme/events', 'url' => 'https://a.example/f.json']],
            ['updates' => []],
            ['updates' => ['github' => 'not a repo']],
            ['updates' => ['github' => 'acme/events', 'license' => true]],
            ['updates' => ['url' => 'http://updates.acme.example/f.json']],
            ['updates' => ['url' => 'https://user:pw@updates.acme.example/f.json']],
            ['updates' => ['url' => 'https://updates.acme.example:8443/f.json']],
            ['updates' => ['url' => 'https://updates.acme.example/f.json', 'license' => 'yes']],
            ['updates' => ['url' => 'https://updates.acme.example/f.json', 'token' => 'x']],
            ['signing_keys' => ['short']],
            ['signing_keys' => 'abc'],
        ] as $bad) {
            try {
                self::manifest($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ManifestException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testAFeedIsReadForItsOwnPackageOnly(): void
    {
        $feed = PackageFeed::parse(self::feed([self::release('1.1.0'), self::release('1.2.0'), ['version' => '1.3.0']], extra: ['links' => ['account.acme.example', 'not a host']]), 'plugin', 'events');
        $this->assertSame(['1.2.0', '1.1.0'], array_column($feed['releases'], 'version'), 'newest first; a release without a download is left out');
        $this->assertSame(['account.acme.example'], $feed['links']);

        foreach ([
            [self::feed([], 'other'), 'another package'],
            [(string) json_encode(['format' => 2, 'type' => 'plugin', 'slug' => 'events', 'releases' => []]), 'format 1'],
            ['<html>', 'format 1'],
            [self::feed(array_fill(0, 101, self::release('1.1.0'))), 'more than 100'],
            [str_repeat(' ', PackageFeed::MAX_BYTES + 1), 'larger than 1 MB'],
        ] as [$body, $why]) {
            try {
                PackageFeed::parse($body, 'plugin', 'events');
                $this->fail("accepted a feed that is {$why}");
            } catch (UnexpectedValueException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function testReleasesThatCantBeTrustedAreLeftOut(): void
    {
        $releases = PackageFeed::parse(self::feed([
            self::release('1.1.0', ['download' => 'http://cdn.acme.example/e.zip']),
            self::release('1.2.0', ['download' => 'https://u:p@cdn.acme.example/e.zip']),
            self::release('1.3.0', ['sha256' => 'not-a-hash']),
            self::release('v1.4.0'),
            self::release('1.5.0', ['requires' => ['core' => 'not a constraint']]),
        ]), 'plugin', 'events')['releases'];

        $this->assertSame(['1.5.0'], array_column($releases, 'version'));
        $this->assertNull($releases[0]['requires_core'], 'an unreadable requirement is checked by the package itself after download');
    }

    public function testTheOfferIsTheNewestTheSiteCanRun(): void
    {
        $releases = PackageFeed::parse(self::feed([
            self::release('2.0.0', ['requires' => ['core' => '^0.2']]),
            self::release('1.3.0-beta.1'),
            self::release('1.2.0', ['requires' => ['core' => '^0.1', 'php' => '>=9.0']]),
            self::release('1.1.0'),
            self::release('1.0.0'),
        ]), 'plugin', 'events')['releases'];

        $choice = PackageFeed::choose($releases, '1.0.0', '0.1.4', '8.4.0', prereleases: false);
        $this->assertSame(['1.1.0', '2.0.0'], [$choice['offer']['version'] ?? null, $choice['needs_core']['version'] ?? null]);
        $this->assertSame('1.3.0-beta.1', PackageFeed::choose($releases, '1.0.0', '0.1.4', '8.4.0', prereleases: true)['offer']['version'] ?? null);
        $this->assertNull(PackageFeed::choose($releases, '1.1.0', '0.1.4', '8.4.0', false)['offer']);
        $this->assertSame('2.0.0', PackageFeed::choose($releases, '1.1.0', '0.2.0', '8.4.0', false)['offer']['version'] ?? null);
    }

    public function testLicenseKeysAreSealedAndNeverShown(): void
    {
        $stored = [];
        $licenses = new PackageLicenses(
            new AppKeySecretStore('base64:' . base64_encode(random_bytes(32))),
            static function (string $owner) use (&$stored): ?string {
                return $stored[$owner] ?? null;
            },
            static function (string $owner, ?string $json) use (&$stored): void {
                $stored[$owner] = $json;
            },
        );
        $this->assertSame(['set' => false, 'last4' => null], $licenses->describe('plugin:events'));
        $licenses->set('plugin:events', '  LIC-7f3a-SECRET-9c21 ');

        $this->assertSame('LIC-7f3a-SECRET-9c21', $licenses->get('plugin:events'));
        $this->assertSame(['set' => true, 'last4' => '9c21'], $licenses->describe('plugin:events'));
        $this->assertStringNotContainsString('SECRET', (string) $stored['plugin:events'], 'sealed at rest');
        $stored['plugin:other'] = $stored['plugin:events'];
        $this->assertNull($licenses->get('plugin:other'), 'a key sealed for one package does not open for another');

        foreach (['', 'has space', str_repeat('k', 501)] as $bad) {
            try {
                $licenses->set('plugin:events', $bad);
                $this->fail("accepted \"{$bad}\"");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $licenses->remove('plugin:events');
        $this->assertNull($licenses->get('plugin:events'));
    }

    public function testUpdatesMustComeFromThePublisherOfTheInstalledVersion(): void
    {
        $stored = [];
        $pins = new KeyPins(
            static function (string $owner) use (&$stored): ?string {
                return $stored[$owner] ?? null;
            },
            static function (string $owner, ?string $json) use (&$stored): void {
                $stored[$owner] = $json;
            },
        );
        $signed = static fn(string $key): array => ['status' => PackageSignature::SIGNED, 'key' => $key];
        $a = '1111111111111111';
        $b = '2222222222222222';

        $this->assertNull($pins->problem('plugin:events', $signed($b)), 'nothing pinned: the usual policy applies');
        $pins->remember('plugin:events', $signed($a), []);
        $this->assertNull($pins->problem('plugin:events', $signed($a)));
        $this->assertStringContainsString('not signed by the publisher', (string) $pins->problem('plugin:events', $signed($b)));
        $this->assertNotNull($pins->problem('plugin:events', ['status' => PackageSignature::UNSIGNED, 'key' => null]));
        $this->assertNotNull($pins->problem('plugin:events', ['status' => PackageSignature::UNTRUSTED, 'key' => $a]), 'the key must still be trusted');

        // rotation: a release signed with the old key names the new one
        $pins->remember('plugin:events', $signed($a), [$b]);
        $this->assertNull($pins->problem('plugin:events', $signed($b)));
        $pins->remember('plugin:events', $signed($b), []);
        $this->assertNotNull($pins->problem('plugin:events', $signed($a)), 'once moved, the old key is no longer enough');

        $pins->remember('plugin:events', ['status' => PackageSignature::UNSIGNED, 'key' => null], []);
        $this->assertNull($pins->pinned('plugin:events'), 'installed unsigned (from the CLI): no pin');
    }
}
