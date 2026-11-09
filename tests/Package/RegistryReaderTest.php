<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Registry\Advisories;
use Kestello\Cms\Package\Registry\Envelope;
use Kestello\Cms\Package\Registry\FileRef;
use Kestello\Cms\Package\Registry\OrganisationFile;
use Kestello\Cms\Package\Registry\PackageFile;
use Kestello\Cms\Package\Registry\RegistryException;
use Kestello\Cms\Package\Registry\RegistryIndex;
use Kestello\Cms\Package\Registry\RegistryKeys;
use Kestello\Framework\Testing\TestCase;

/**
 * Reading the registry (planning spec/registry-format.md): the root-signed key list, the signed
 * index, and the files it pins by hash. Every test builds a small registry with throwaway keys
 * and breaks one thing.
 */
final class RegistryReaderTest extends TestCase
{
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $root;
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $index;
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $acme;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = PackageSignature::generateKeys();
        $this->index = PackageSignature::generateKeys();
        $this->acme = PackageSignature::generateKeys();
        $this->now = (int) strtotime('2027-01-15T12:00:00Z');
    }

    // ---- building a registry -------------------------------------------------------------

    private static function at(string $when): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($when, (int) strtotime('2027-01-15T12:00:00Z')));
    }

    /** @return array<string, string> */
    private function roots(): array
    {
        return [$this->root['id'] => (string) base64_decode($this->root['public_key'], true)];
    }

    /** @param array<string, mixed> $payload */
    private static function seal(array $payload, string $kind, string ...$secrets): array
    {
        return Envelope::seal((string) json_encode($payload), $secrets, $kind);
    }

    /** @param array<string, mixed> $over */
    private function keysBody(array $over = [], ?string $signer = null): string
    {
        $payload = $over + [
            'kind' => 'keys', 'format' => 1, 'serial' => 3,
            'issued_at' => self::at('-10 days'), 'expires_at' => self::at('+300 days'),
            'index_keys' => [['key' => $this->index['id'], 'public_key' => $this->index['public_key'], 'valid_from' => self::at('-30 days'), 'valid_until' => self::at('+330 days')]],
            'revoked' => [],
        ];

        return (string) json_encode(self::seal($payload, Envelope::KEY, $signer ?? $this->root['secret_key']));
    }

    private function keys(array $over = []): RegistryKeys
    {
        return RegistryKeys::verify($this->keysBody($over), $this->roots(), $this->now);
    }

    /** @param array<string, mixed> $over */
    private function orgCert(string $organisation = 'acme', array $over = [], ?string $signer = null): array
    {
        return self::seal($over + [
            'kind' => 'organisation', 'organisation' => $organisation, 'key' => $this->acme['id'], 'public_key' => $this->acme['public_key'],
            'valid_from' => self::at('-60 days'), 'valid_until' => self::at('+700 days'),
        ], Envelope::KEY, $signer ?? $this->root['secret_key']);
    }

    /** @param array<string, mixed> $over */
    private static function version(string $version, array $over = []): array
    {
        return $over + [
            'version' => $version, 'released_at' => self::at('-5 days'),
            'requires' => ['core' => '^0.1', 'php' => '>=8.4'], 'dependencies' => ['forms' => '^1.0'],
            'permissions' => ['network' => ['api.acme.example']], 'notes' => "Notes for {$version}",
            'download' => "dl/events/{$version}/" . str_repeat('0', 64) . '.zip', 'sha256' => str_repeat('0', 64), 'size' => 1000,
            'key' => '0123456789abcdef',
        ];
    }

    /** @param array<string, mixed> $over */
    private static function packageBytes(array $over = []): string
    {
        return (string) json_encode($over + [
            'format' => 1, 'slug' => 'events', 'type' => 'plugin', 'organisation' => 'acme',
            'name' => 'Events', 'summary' => 'Event listings.', 'description' => "# Events\n\nListings.", 'license' => 'GPL-2.0-or-later',
            'homepage' => 'https://acme.example/events', 'categories' => ['content'],
            'screenshots' => [['image' => 'img/' . str_repeat('a', 64) . '.webp', 'caption' => 'The list']],
            'commerce' => null,
            'versions' => [self::version('1.4.0'), self::version('1.5.0'), self::version('1.6.0-rc.1')],
        ]);
    }

    /** @return array{path: string, sha256: string, size: int} */
    private static function ref(string $path, string $bytes): array
    {
        return ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    /** @param array<string, mixed> $over */
    private function indexBody(array $over = [], ?string $signer = null): string
    {
        $package = self::packageBytes();
        $payload = $over + [
            'format' => 1, 'serial' => 1832, 'issued_at' => self::at('-1 hour'), 'expires_at' => self::at('+13 days'),
            'packages' => [[
                'slug' => 'events', 'type' => 'plugin', 'name' => 'Events', 'summary' => 'Event listings.', 'organisation' => 'acme',
                'categories' => ['content'], 'latest' => ['stable' => '1.5.0', 'prerelease' => '1.6.0-rc.1'], 'requires_core' => '^0.1',
                'paid' => false, 'updated_at' => self::at('-5 days'), 'file' => self::ref('p/events/' . hash('sha256', $package) . '.json', $package),
            ]],
            'organisations' => [['id' => 'acme', 'name' => 'Acme', 'verified' => 'acme.example', 'file' => self::ref('o/acme/' . str_repeat('b', 64) . '.json', 'x')]],
            'advisories' => self::ref('advisories/' . str_repeat('c', 64) . '.json', 'x'),
            'moves' => [],
        ];

        return (string) json_encode(self::seal($payload, Envelope::INDEX, $signer ?? $this->index['secret_key']));
    }

    private function index(array $over = [], ?int $lastSerial = null): RegistryIndex
    {
        return RegistryIndex::verify($this->indexBody($over), $this->keys(), $this->roots(), $this->now, $lastSerial);
    }

    private function refused(string $expected, callable $read): void
    {
        try {
            $read();
            $this->fail("Expected the registry file to be refused ({$expected}).");
        } catch (RegistryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    // ---- keys.json -----------------------------------------------------------------------

    public function testTheKeyListNamesTheIndexKeysTheRootVouchesFor(): void
    {
        $keys = $this->keys();
        $this->assertSame(3, $keys->serial);
        $this->assertSame([$this->index['id']], array_keys($keys->indexKeysAt($this->now)));
        $this->assertSame([], $keys->indexKeysAt((int) strtotime('+2 years', $this->now)), 'past its validity');
        $this->assertNull($keys->revoked($this->index['id']));
    }

    public function testTheKeyListMustBeSignedByARootKey(): void
    {
        $this->refused('not signed by a key Kestello trusts', fn() => RegistryKeys::verify($this->keysBody(signer: $this->index['secret_key']), $this->roots(), $this->now));
    }

    public function testTheKeyListIsNeverRolledBackOrStale(): void
    {
        $this->refused('older than one this site has already seen', fn() => RegistryKeys::verify($this->keysBody(), $this->roots(), $this->now, 4));
        $this->assertSame(3, RegistryKeys::verify($this->keysBody(), $this->roots(), $this->now, 3)->serial, 'the same list again is fine');
        $this->refused('has expired', fn() => $this->keys(['issued_at' => self::at('-400 days'), 'expires_at' => self::at('-2 days')]));
        $this->refused('dated in the future', fn() => $this->keys(['issued_at' => self::at('+3 days'), 'expires_at' => self::at('+300 days')]));
        $this->refused('no valid serial number or dates', fn() => $this->keys(['expires_at' => self::at('+500 days')]));
    }

    public function testOneBadEntryRefusesTheWholeKeyList(): void
    {
        $this->refused('index key that can\'t be read', fn() => $this->keys(['index_keys' => [['key' => 'ffffffffffffffff', 'public_key' => $this->index['public_key'], 'valid_from' => self::at('-1 day'), 'valid_until' => self::at('+1 day')]]]));
        $this->refused('revoked key that can\'t be read', fn() => $this->keys(['revoked' => [['key' => $this->acme['id'], 'at' => 'yesterday', 'reason' => 'Leaked']]]));
        $this->refused('is not a key list', fn() => $this->keys(['kind' => 'organisation']));
    }

    public function testARevokedIndexKeyOpensNothing(): void
    {
        $keys = $this->keys(['revoked' => [['key' => $this->index['id'], 'at' => self::at('-1 day'), 'reason' => 'Leaked']]]);
        $this->assertSame([], $keys->indexKeysAt($this->now));
        $this->assertSame('Leaked', $keys->revoked($this->index['id'])['reason'] ?? null);
        $this->refused('not signed by a key Kestello trusts', fn() => RegistryIndex::verify($this->indexBody(), $keys, $this->roots(), $this->now));
    }

    // ---- the index -----------------------------------------------------------------------

    public function testTheIndexListsPackagesAndOrganisations(): void
    {
        $index = $this->index();
        $this->assertSame(1832, $index->serial);
        $this->assertSame(['events'], array_keys($index->packages));
        $this->assertSame('1.5.0', $index->packages['events']['stable']);
        $this->assertSame('1.6.0-rc.1', $index->packages['events']['prerelease']);
        $this->assertSame('acme.example', $index->organisations['acme']['verified']);
        $this->assertStringStartsWith('advisories/', $index->advisories->path);
    }

    public function testOnlyACurrentIndexKeyMaySignTheIndex(): void
    {
        $this->refused('not signed by a key Kestello trusts', fn() => RegistryIndex::verify($this->indexBody(signer: $this->acme['secret_key']), $this->keys(), $this->roots(), $this->now));
        // the root itself can't sign the index either: it isn't an index key, and the domains differ
        $this->refused('not signed by a key Kestello trusts', fn() => RegistryIndex::verify($this->indexBody(signer: $this->root['secret_key']), $this->keys(), $this->roots(), $this->now));
    }

    public function testASignatureOfOneKindNeverPassesAsAnother(): void
    {
        // the index key signs a key list with the index domain: refused as a key list even if the
        // index key were a root
        $payload = (string) json_encode(['kind' => 'keys', 'format' => 1]);
        $asIndex = Envelope::seal($payload, [$this->index['secret_key']], Envelope::INDEX);
        $trustingIndexKey = [$this->index['id'] => (string) base64_decode($this->index['public_key'], true)];
        $this->refused('not signed by a key Kestello trusts', fn() => Envelope::open($asIndex, $trustingIndexKey, Envelope::KEY, 'key list'));
        $this->assertSame($payload, Envelope::open($asIndex, $trustingIndexKey, Envelope::INDEX, 'list'));
    }

    public function testATamperedIndexIsRefused(): void
    {
        $envelope = json_decode($this->indexBody(), true);
        $payload = json_decode((string) base64_decode($envelope['payload']), true);
        $payload['packages'][0]['organisation'] = 'mallory';
        $envelope['payload'] = base64_encode((string) json_encode($payload));
        $this->refused('not signed by a key Kestello trusts', fn() => RegistryIndex::verify((string) json_encode($envelope), $this->keys(), $this->roots(), $this->now));
    }

    public function testUnknownAlgorithmsAreSkippedNotFatal(): void
    {
        $envelope = json_decode($this->indexBody(), true);
        array_unshift($envelope['signatures'], ['alg' => 'ml-dsa-65', 'key' => 'abc', 'sig' => 'xyz']);
        $this->assertSame(1832, RegistryIndex::verify((string) json_encode($envelope), $this->keys(), $this->roots(), $this->now)->serial);
    }

    public function testAStaleOrRolledBackIndexIsRefused(): void
    {
        $this->refused('older than one this site has already seen', fn() => $this->index([], 1833));
        $this->refused('has expired', fn() => $this->index(['issued_at' => self::at('-20 days'), 'expires_at' => self::at('-6 days')]));
        $this->refused('no valid serial number or dates', fn() => $this->index(['expires_at' => self::at('+20 days')]));
        $this->refused('no valid serial number or dates', fn() => $this->index(['serial' => '1832']));
    }

    public function testTheClockMayBeADayOut(): void
    {
        $this->assertSame(1832, $this->index(['issued_at' => self::at('-14 days'), 'expires_at' => self::at('-12 hours')])->serial);
        $this->assertSame(1832, $this->index(['issued_at' => self::at('+20 hours'), 'expires_at' => self::at('+10 days')])->serial);
    }

    public function testABadPackageEntryIsLeftOutAndADuplicateRefusesTheIndex(): void
    {
        $good = json_decode((string) base64_decode(json_decode($this->indexBody(), true)['payload']), true)['packages'][0];
        $index = $this->index(['packages' => [$good, ['slug' => 'ab'] + $good, ['slug' => 'Bad Name'] + $good, ['latest' => []] + $good]]);
        $this->assertSame(['events'], array_keys($index->packages));
        $this->refused('names the package "events" twice', fn() => $this->index(['packages' => [$good, $good]]));
    }

    public function testFileReferencesStayInsideTheRegistry(): void
    {
        foreach (['../keys.json', '/etc/passwd', 'p/../../x.json', 'https://evil.example/x.json', 'p//x.json', 'P/Events.json'] as $path) {
            $this->assertNull(FileRef::parse(['path' => $path, 'sha256' => str_repeat('a', 64), 'size' => 10], 100), $path);
        }
        $this->assertNull(FileRef::parse(['path' => 'p/x.json', 'sha256' => str_repeat('a', 64), 'size' => 101], 100), 'over the limit');
        $this->refused('advisories file', fn() => $this->index(['advisories' => ['path' => '../a.json', 'sha256' => str_repeat('c', 64), 'size' => 1]]));
    }

    public function testMovesMustBeSignedByTheRoot(): void
    {
        $move = ['kind' => 'move', 'slug' => 'events', 'from' => 'acme', 'to' => 'northwind', 'issued_at' => self::at('-1 day')];
        $index = $this->index(['moves' => [self::seal($move, Envelope::KEY, $this->root['secret_key'])]]);
        $this->assertSame([['from' => 'acme', 'to' => 'northwind', 'issued_at' => (int) strtotime(self::at('-1 day'))]], $index->moves['events']);

        $this->refused('not signed by a key Kestello trusts', fn() => $this->index(['moves' => [self::seal($move, Envelope::KEY, $this->index['secret_key'])]]));
        $this->refused('move certificate that can\'t be read', fn() => $this->index(['moves' => [self::seal(['kind' => 'organisation'] + $move, Envelope::KEY, $this->root['secret_key'])]]));
    }

    public function testOversizedFilesAreRefusedBeforeTheyAreRead(): void
    {
        $this->refused('larger than 8 MB', fn() => RegistryIndex::verify(str_repeat(' ', RegistryIndex::MAX_BYTES + 1), $this->keys(), $this->roots(), $this->now));
        $this->refused('larger than 256 KB', fn() => RegistryKeys::verify(str_repeat(' ', RegistryKeys::MAX_BYTES + 1), $this->roots(), $this->now));
        $this->refused('is not readable JSON', fn() => RegistryKeys::verify('[1,2]', $this->roots(), $this->now));
    }

    // ---- the files the index pins ----------------------------------------------------------

    public function testAPackageFileMatchingTheIndexIsReadNewestFirst(): void
    {
        $package = PackageFile::parse(self::packageBytes(), $this->index()->packages['events']);
        $this->assertSame(['1.6.0-rc.1', '1.5.0', '1.4.0'], array_column($package['versions'], 'version'));
        $this->assertTrue($package['versions'][0]['prerelease']);
        $this->assertSame(['forms' => '^1.0'], $package['versions'][1]['dependencies']);
        $this->assertSame('GPL-2.0-or-later', $package['license']);
        $this->assertNull($package['commerce']);
    }

    public function testAPackageFileMustBeTheOneTheIndexNames(): void
    {
        $entry = $this->index()->packages['events'];
        $this->refused("doesn't match the index", fn() => PackageFile::parse(self::packageBytes() . ' ', $entry));
        $this->refused("doesn't match the index", fn() => PackageFile::parse(self::packageBytes(['name' => 'Evil']), $entry));
    }

    public function testAPackageFileMustAgreeWithItsIndexEntry(): void
    {
        $bytes = self::packageBytes(['organisation' => 'mallory']);
        $entry = ['file' => FileRef::parse(self::ref('p/events/x.json', $bytes), 1 << 20)] + $this->index()->packages['events'];
        $this->refused("doesn't agree with the list", fn() => PackageFile::parse($bytes, $entry));
    }

    public function testBadVersionsAreLeftOutAndDuplicatesRefused(): void
    {
        $bytes = self::packageBytes(['versions' => [
            self::version('1.5.0'),
            self::version('1.5.1', ['sha256' => 'nope']),
            self::version('1.5.2', ['download' => null]),                // a free version must have a copy
            self::version('1.5.3', ['requires' => []]),                   // requires.core is required
            self::version('1.5.4', ['withdrawn' => ['at' => self::at('-1 day'), 'reason' => 'Breaks forms']]),
        ]]);
        $entry = ['file' => FileRef::parse(self::ref('p/events/x.json', $bytes), 1 << 20)] + $this->index()->packages['events'];
        $versions = PackageFile::parse($bytes, $entry)['versions'];
        $this->assertSame(['1.5.4', '1.5.0'], array_column($versions, 'version'));
        $this->assertSame('Breaks forms', $versions[0]['withdrawn']['reason'] ?? null);

        $bytes = self::packageBytes(['versions' => [self::version('1.5.0'), self::version('1.5.0')]]);
        $entry = ['file' => FileRef::parse(self::ref('p/events/x.json', $bytes), 1 << 20)] + $entry;
        $this->refused('lists version 1.5.0 twice', fn() => PackageFile::parse($bytes, $entry));
    }

    public function testAPaidPackageHasNoRegistryDownloadAndAStoreLink(): void
    {
        $commerce = ['model' => 'yearly-per-site', 'price' => 'From 49 EUR a year', 'store_url' => 'https://acme.example/buy', 'license' => 'seller'];
        $bytes = self::packageBytes(['commerce' => $commerce, 'versions' => [self::version('2.0.0', ['download' => null]), self::version('2.0.1')]]);
        $entry = ['paid' => true, 'file' => FileRef::parse(self::ref('p/events/x.json', $bytes), 1 << 20)] + $this->index()->packages['events'];
        $package = PackageFile::parse($bytes, $entry);
        $this->assertSame($commerce, $package['commerce']);
        $this->assertSame(['2.0.0'], array_column($package['versions'], 'version'), 'a paid version never names a registry copy');

        $this->refused("can't be read", fn() => PackageFile::parse($bytes, ['paid' => false] + $entry));
        $bytes = self::packageBytes(['commerce' => ['model' => 'lifetime'] + $commerce]);
        $this->refused("can't be read", fn() => PackageFile::parse($bytes, ['file' => FileRef::parse(self::ref('p/events/x.json', $bytes), 1 << 20)] + $entry));
    }

    public function testAnOrganisationsKeysAreCertifiedByTheRoot(): void
    {
        $bytes = (string) json_encode(['format' => 1, 'id' => 'acme', 'name' => 'Acme', 'verified' => ['domain' => 'acme.example', 'at' => self::at('-90 days')], 'prefix' => 'acme-', 'keys' => [$this->orgCert()]]);
        $entry = ['id' => 'acme', 'file' => FileRef::parse(self::ref('o/acme/x.json', $bytes), 1 << 16)];
        $org = OrganisationFile::parse($bytes, $entry, $this->roots());
        $this->assertSame('acme.example', $org->verifiedDomain);
        $this->assertSame('acme-', $org->prefix);
        $this->assertSame($this->acme['id'], $org->keyAt($this->acme['id'], $this->now)?->id);
        $this->assertNull($org->keyAt($this->acme['id'], (int) strtotime('+3 years', $this->now)), 'after the certificate ends');
        $this->assertNull($org->keyAt('0123456789abcdef', $this->now));
    }

    public function testAnOrganisationCantCarryAnotherOrganisationsOrAnUncertifiedKey(): void
    {
        foreach ([[$this->orgCert('northwind'), 'certified for northwind'], [$this->orgCert(signer: $this->acme['secret_key']), 'not signed by a key Kestello trusts']] as [$cert, $expected]) {
            $bytes = (string) json_encode(['format' => 1, 'id' => 'acme', 'name' => 'Acme', 'keys' => [$cert]]);
            $entry = ['id' => 'acme', 'file' => FileRef::parse(self::ref('o/acme/x.json', $bytes), 1 << 16)];
            $this->refused($expected, fn() => OrganisationFile::parse($bytes, $entry, $this->roots()));
        }
    }

    public function testAdvisoriesApplyToTheVersionsTheyName(): void
    {
        $bytes = (string) json_encode(['format' => 1, 'advisories' => [
            ['id' => 'KSA-2027-0001', 'type' => 'plugin', 'package' => 'events', 'affected' => '<1.4.3', 'fixed' => '1.4.3', 'severity' => 'medium', 'title' => 'A', 'url' => 'https://kestello.com/security/KSA-2027-0001', 'published_at' => self::at('-3 days')],
            ['id' => 'KSA-2027-0002', 'type' => 'plugin', 'package' => 'events', 'affected' => '>=1.0.0 <1.5.0', 'fixed' => '1.5.0', 'severity' => 'critical', 'title' => 'B', 'url' => 'https://kestello.com/security/KSA-2027-0002', 'published_at' => self::at('-2 days')],
            ['id' => 'KSA-2027-0003', 'type' => 'core', 'package' => 'core', 'affected' => '<0.1.1', 'severity' => 'high', 'title' => 'C', 'url' => 'https://kestello.com/security/KSA-2027-0003', 'published_at' => self::at('-1 day')],
            ['id' => 'not-an-id', 'type' => 'plugin', 'package' => 'events', 'affected' => '*', 'severity' => 'low', 'title' => 'D', 'url' => 'https://x.example', 'published_at' => self::at('-1 day')],
        ]]);
        $advisories = Advisories::parse($bytes, FileRef::parse(self::ref('advisories/x.json', $bytes), 1 << 21));
        $this->assertCount(3, $advisories->all, 'the unreadable one is left out');
        $this->assertSame(['KSA-2027-0002', 'KSA-2027-0001'], array_column($advisories->affecting('plugin', 'events', '1.4.2'), 'id'), 'most severe first');
        $this->assertSame([], $advisories->affecting('plugin', 'events', '1.5.0'));
        $this->assertSame(['KSA-2027-0003'], array_column($advisories->affecting('core', 'core', '0.1.0-rc.3'), 'id'));
    }
}
