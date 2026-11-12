<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Health\HealthResult;
use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Registry\InstalledAdvisories;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Cms\Package\Registry\RegistryPackages;
use Kestello\Cms\Package\Registry\RegistryTrust;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\KeyPins;
use Kestello\Cms\Package\Update\UpdateProbe;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * Installing and updating from the registry (registry-format.md §5): the registry vouches for exact
 * bytes, the zip must carry its organisation's certified key, and nothing else gets past.
 */
final class RegistryInstallTest extends AuthTestCase
{
    private string $root;
    private ArrayStateStore $state;
    private RegistryFixture $registry;
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        $this->cms->storage->configure($this->root . '/storage/app', []);
        $this->state = new ArrayStateStore();
        $this->boot();
        foreach ([new BackupModule(), new PackagesModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $test = $this;
        $this->cms->container->instance(HealthProbe::class, new class ($test) implements HealthProbe {
            public function __construct(private RegistryInstallTest $test) {}

            public function run(string $owner, string $bypass): array
            {
                $this->test->boot();

                return UpdateProbe::report($this->test->cms(), $owner, pages: false);
            }
        });
        // the admin's policy: signed packages only, and this site trusts no key of its own
        $this->cms->config->set('app.packages.admin_unsigned', false);
        $this->cms->config->set('app.packages.trusted_keys', []);

        $this->registry = new RegistryFixture();
        $this->acme = $this->registry->organisation('acme', 'Acme');
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.0.0', $this->acme), '1.0.0');
        $this->registry->publish();
        $client = $this->registry->client($this->root . '/storage/registry');
        $this->cms->container->instance(RegistryClient::class, $client);
        $this->assertNull($client->refresh()['error']);
        $this->createUser();
    }

    public function boot(): void
    {
        $ext = new ExtensionManager($this->cms, $this->state, new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $ext->boot();
        $this->cms->setExtensions($ext);
    }

    public function cms(): \Kestello\Cms\Cms
    {
        return $this->cms;
    }

    /**
     * Build version $version of the "ledger" plugin, signed with $key (unsigned when null).
     *
     * @param array{secret_key: string}|null $key
     * @param array<string, mixed> $manifest
     */
    private function zip(string $version, ?array $key, array $manifest = []): string
    {
        $dir = $this->root . '/src/' . bin2hex(random_bytes(4)) . '/ledger';
        mkdir($dir, 0o777, true);
        file_put_contents("{$dir}/plugin.json", json_encode($manifest + ['slug' => 'ledger', 'name' => 'Ledger', 'version' => $version, 'requires' => ['core' => '>=0.1.0-0']]));
        file_put_contents("{$dir}/VERSION.txt", $version);
        $zip = PackageBuilder::build($dir, $this->root . '/dist/' . basename(dirname($dir)))['zip'];
        if ($key !== null) {
            PackageSignature::sign($zip, $key['secret_key']);
        }

        return $zip;
    }

    private function installer(): PackageInstaller
    {
        return $this->cms->container->make(PackageInstaller::class);
    }

    private function packages(): RegistryPackages
    {
        return PackagesModule::registryPackages($this->cms->container) ?? throw new \RuntimeException('registry off');
    }

    private function pins(): KeyPins
    {
        return $this->cms->container->make(KeyPins::class);
    }

    /** Publish a change and let the site see it. */
    private function republish(): void
    {
        $this->registry->publish();
        $this->assertNull($this->cms->container->make(RegistryClient::class)->refresh()['error']);
    }

    private function installFromRegistry(string $version = '1.0.0'): array
    {
        $zip = $this->packages()->download('plugin', 'ledger', $version);

        return $this->installer()->install($zip, ['require_registry' => true]);
    }

    /** @return list<string> */
    private function refusal(callable $fn): array
    {
        try {
            $fn();
        } catch (ContentException $e) {
            return array_merge(...array_values($e->errors !== [] ? $e->errors : [[$e->getMessage()]]));
        }
        $this->fail('Expected the install to be refused.');
    }

    private function installedVersion(): string
    {
        return trim((string) file_get_contents($this->root . '/content/plugins/ledger/VERSION.txt'));
    }

    public function testAPackageInstallsFromTheRegistryAndIsPinnedToItsOrganisation(): void
    {
        $this->assertSame('1.0.0', $this->packages()->choose('plugin', 'ledger', null));
        $result = $this->installFromRegistry();

        $this->assertSame('1.0.0', $this->installedVersion());
        $check = $result['check'];
        $this->assertSame([RegistryTrust::VOUCHED, 'acme', 'Acme'], [$check['registry']['status'], $check['registry']['organisation'], $check['registry']['organisation_name']]);
        $this->assertArrayNotHasKey('trusted', $check['registry']);
        $this->assertSame([PackageSignature::SIGNED, $this->acme['id'], 'Acme (registry)'], [$check['signature']['status'], $check['signature']['key'], $check['signature']['name']]);
        $this->assertSame('acme', $this->pins()->organisation('plugin:ledger'));
        $this->assertSame([], $this->cms->config->get('app.packages.trusted_keys'), 'nothing was added to the site\'s own trust');

        $row = array_values(array_filter(PackagesModule::installedPackages($this->cms), static fn(array $p): bool => $p['owner'] === 'plugin:ledger'))[0];
        $this->assertSame(['registry' => true], $row['updates'], 'its updates now come from the registry');
    }

    public function testTheSameBytesUploadedCountAsTheRegistrysButNotARebuild(): void
    {
        // exactly the registry's zip, uploaded by hand: vouched for, though the site trusts no key
        $upload = $this->root . '/upload.zip';
        copy($this->packages()->download('plugin', 'ledger', '1.0.0'), $upload);
        $this->assertSame(RegistryTrust::VOUCHED, $this->installer()->inspect($upload)['registry']['status']);

        // the same version changed and signed with the same key is other bytes: not the registry's
        $rebuilt = $this->zip('1.0.0', $this->acme, ['description' => 'Changed after review.']);
        $check = $this->installer()->inspect($rebuilt);
        $this->assertSame([RegistryTrust::UNKNOWN, null], [$check['registry']['status'], $check['registry']['reason']]);
        $this->assertSame(PackageSignature::UNTRUSTED, $check['signature']['status']);
        $this->assertStringContainsString('signed by a key this site does not trust', $this->refusal(fn() => $this->installer()->install($rebuilt))[0] ?? '');
        $this->assertStringContainsString("doesn't vouch", $this->refusal(fn() => $this->installer()->install($rebuilt, ['require_registry' => true, 'allow_unsigned' => true]))[0] ?? '');
    }

    public function testAnUpdateComesFromTheRegistryThroughTheAdmin(): void
    {
        $this->installFromRegistry();
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', $this->acme), '1.1.0');
        $this->republish();
        $this->registry->transport->sent = [];

        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $data = json_decode($this->api('POST', '/updates/packages/check')->content(), true)['data'];
        $row = array_values(array_filter($data['plugins'], static fn(array $p): bool => $p['slug'] === 'ledger'))[0];
        $this->assertSame(['registry', '1.1.0'], [$row['source'], $row['update']['offer']['version'] ?? null], (string) json_encode($row));

        $review = json_decode($this->api('POST', '/updates/packages/download', ['owner' => 'plugin:ledger'])->content(), true)['data'];
        $this->assertSame([true, [], 'signed', 'vouched'], [$review['allowed'], $review['package']['problems'], $review['package']['signature']['status'], $review['package']['registry']['status']], (string) json_encode($review));
        $done = $this->api('POST', '/updates/packages/update', ['token' => $review['token'], 'owner' => 'plugin:ledger']);
        $this->assertTrue(json_decode($done->content(), true)['data']['result']['ok'] ?? false, $done->content());
        $this->assertSame('1.1.0', $this->installedVersion());
        $this->assertSame('acme', $this->pins()->organisation('plugin:ledger'));

        foreach ($this->registry->transport->sent as $request) {
            $this->assertSame('Kestello', $request->headers['User-Agent'] ?? null, "{$request->url} says nothing about the site");
            $this->assertArrayNotHasKey('Authorization', $request->headers);
            $this->assertArrayNotHasKey('X-Kestello-Site', $request->headers);
        }
    }

    public function testAWithdrawnVersionIsRefusedEverywhereEvenFromTheCommandLine(): void
    {
        $zip = $this->packages()->download('plugin', 'ledger', '1.0.0');
        $this->registry->amend('ledger', '1.0.0', ['withdrawn' => ['at' => RegistryFixture::at($this->registry->now - 60), 'reason' => 'It deletes posts.']]);
        $this->republish();

        $this->assertNull($this->packages()->choose('plugin', 'ledger', null), 'never offered');
        $this->assertSame(['Ledger 1.0.0 was withdrawn from the registry: It deletes posts.'], $this->refusal(fn() => $this->installer()->install($zip, ['allow_unsigned' => true])));
        $this->assertFalse(is_dir($this->root . '/content/plugins/ledger'));
        $this->assertSame(['Ledger 1.0.0 was withdrawn from the registry: It deletes posts.'], $this->cms->container->make(Updater::class)->check($zip)['problems'], 'and the update review says so first');
    }

    /** @return array<string, mixed> the admin's review of an uploaded zip */
    private function upload(string $zip): array
    {
        $copy = $this->root . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        copy($zip, $copy);
        $this->nextRequest();
        $response = $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request(
            'POST',
            '/api/v1/plugins/upload',
            '/api/v1/plugins/upload',
            [],
            [],
            [],
            [],
            ['file' => ['name' => 'ledger.zip', 'type' => 'application/zip', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        ));
        $this->assertSame(200, $response->statusCode(), $response->content());

        return json_decode($response->content(), true)['data'];
    }

    public function testTheAdminsUploadReviewSaysWhatTheRegistrySays(): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $zip = $this->packages()->download('plugin', 'ledger', '1.0.0');

        $review = $this->upload($zip);
        $this->assertSame([true, 'vouched', 'Acme (registry)'], [$review['allowed'], $review['package']['registry']['status'], $review['package']['signature']['name']], 'no trusted key needed');

        // withdrawn: refused even where the admin may install unsigned zips (a development site)
        $this->cms->config->set('app.packages.admin_unsigned', true);
        $this->registry->amend('ledger', '1.0.0', ['withdrawn' => ['at' => RegistryFixture::at($this->registry->now - 60), 'reason' => 'It deletes posts.']]);
        $this->republish();
        $review = $this->upload($zip);
        $this->assertSame([false, 'refused', 'Ledger 1.0.0 was withdrawn from the registry: It deletes posts.'], [$review['allowed'], $review['package']['registry']['status'], $review['package']['registry']['reason']]);
        $installed = $this->api('POST', '/plugins/install', ['token' => $review['token']]);
        $this->assertSame(422, $installed->statusCode(), $installed->content());
        $this->assertStringContainsString('It deletes posts.', $installed->content(), 'refused by the registry, not for a missing field');
        $this->assertFalse(is_dir($this->root . '/content/plugins/ledger'));
    }

    public function testARevokedKeyIsRefused(): void
    {
        $zip = $this->packages()->download('plugin', 'ledger', '1.0.0');
        $this->registry->revoked[$this->acme['id']] = 'Lost';
        $this->republish();

        $this->assertStringContainsString('signed with a key the registry has revoked', $this->refusal(fn() => $this->installer()->install($zip, ['allow_unsigned' => true]))[0] ?? '');
    }

    public function testAKeyCertifiedForAnotherOrganisationIsRefused(): void
    {
        // northwind's key signs a zip listed under acme
        $northwind = $this->registry->organisation('northwind', 'Northwind');
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', $northwind), '1.1.0');
        $this->republish();
        $zip = $this->packages()->download('plugin', 'ledger', '1.1.0');

        $this->assertStringContainsString("which the registry hasn't certified for acme", $this->refusal(fn() => $this->installer()->install($zip, ['allow_unsigned' => true]))[0] ?? '');
    }

    public function testAKeyCertifiedOnlyAfterTheReleaseIsRefused(): void
    {
        $late = \Kestello\Cms\Package\PackageSignature::generateKeys();
        $this->registry->certify('acme', $late, $this->registry->now - 86_400 * 3, $this->registry->now + 86_400 * 300);
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', $late), '1.1.0', ['released_at' => RegistryFixture::at($this->registry->now - 86_400 * 30)]);
        $this->republish();

        $this->assertStringContainsString("hasn't certified for acme on the day it was released", $this->refusal(fn() => $this->installer()->install($this->packages()->download('plugin', 'ledger', '1.1.0')))[0] ?? '');
    }

    public function testTheZipMustBeSignedByTheKeyTheVersionNames(): void
    {
        // listed as signed by acme's key, but the zip carries another key acme also holds
        $second = $this->registry->organisation('acme', 'Acme');
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', $second), '1.1.0', keyId: $this->acme['id']);
        $this->republish();

        $this->assertStringContainsString("isn't signed by the key it names", $this->refusal(fn() => $this->installer()->install($this->packages()->download('plugin', 'ledger', '1.1.0')))[0] ?? '');
    }

    public function testTheSitesOwnTrustedKeysDontCountForARegistryZip(): void
    {
        // the zip is signed by a key this site trusts, but the registry names acme's key for it
        $own = PackageSignature::generateKeys();
        $this->cms->config->set('app.packages.trusted_keys', [['name' => 'Our agency', 'public_key' => $own['public_key']]]);
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', $own), '1.1.0', keyId: $this->acme['id']);
        $this->republish();

        $this->assertStringContainsString("isn't signed by the key it names", $this->refusal(fn() => $this->installer()->install($this->packages()->download('plugin', 'ledger', '1.1.0')))[0] ?? '');
    }

    public function testAnUnsignedZipTheRegistryListsIsRefused(): void
    {
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', null), '1.1.0', keyId: $this->acme['id']);
        $this->republish();

        $this->assertStringContainsString("isn't signed by the key it names", $this->refusal(fn() => $this->installer()->install($this->packages()->download('plugin', 'ledger', '1.1.0'), ['allow_unsigned' => true]))[0] ?? '');
    }

    public function testTheManifestMustBeTheVersionTheRegistryListed(): void
    {
        // the registry lists these bytes as 1.1.0, but the manifest inside says 2.0.0
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('2.0.0', $this->acme), '1.1.0');
        $this->republish();

        $this->assertSame(['This zip is Ledger 1.1.0 in the registry, but its manifest says version 2.0.0.'], $this->refusal(fn() => $this->installer()->install($this->packages()->download('plugin', 'ledger', '1.1.0'), ['allow_unsigned' => true])));
    }

    public function testAnUpdateFromElsewhereCantReplaceARegistryPackage(): void
    {
        $this->installFromRegistry();
        // a key the site trusts signs a 1.1.0 the registry has never seen
        $other = PackageSignature::generateKeys();
        $this->cms->config->set('app.packages.trusted_keys', [['name' => 'Someone', 'public_key' => $other['public_key']]]);
        $zip = $this->zip('1.1.0', $other);

        $check = $this->cms->container->make(Updater::class)->check($zip);
        $this->assertSame(PackageSignature::SIGNED, $check['signature']['status']);
        $this->assertStringContainsString('is not a version the registry vouches for, and the installed version came from the registry (organisation acme)', implode(' ', $check['problems']));
        $this->assertSame('1.0.0', $this->installedVersion());

        // the same zip signed by acme's own key, but never published to the registry: still no
        PackageSignature::sign($zip, $this->acme['secret_key']);
        $this->assertNotSame([], $this->cms->container->make(Updater::class)->check($zip)['problems']);
    }

    public function testAPackageFollowsAMoveTheRootSigned(): void
    {
        $this->installFromRegistry();
        $northwind = $this->registry->organisation('northwind', 'Northwind');
        $this->registry->move('ledger', 'acme', 'northwind');
        $this->registry->version('plugin', 'ledger', 'northwind', $this->zip('1.1.0', $northwind), '1.1.0');
        $this->republish();

        $zip = $this->packages()->download('plugin', 'ledger', '1.1.0');
        $check = $this->cms->container->make(Updater::class)->check($zip);
        $this->assertSame([[], 'northwind', ['acme']], [$check['problems'], $check['registry']['organisation'], $check['registry']['moved_from']]);
        $this->assertTrue($this->cms->container->make(Updater::class)->update($zip)['ok']);
        $this->assertSame('northwind', $this->pins()->organisation('plugin:ledger'));

        // and acme's older versions still count as the package's, signed before the move
        $this->assertSame(RegistryTrust::VOUCHED, $this->installer()->inspect($this->packages()->download('plugin', 'ledger', '1.0.0'))['registry']['status']);
    }

    public function testWithoutAMoveAnotherOrganisationCantTakeAPackageOver(): void
    {
        $this->installFromRegistry();
        // the registry now lists ledger under northwind, with no root-signed move
        $northwind = $this->registry->organisation('northwind', 'Northwind');
        $this->registry->version('plugin', 'ledger', 'northwind', $this->zip('1.1.0', $northwind), '1.1.0');
        $this->republish();

        $check = $this->cms->container->make(Updater::class)->check($this->packages()->download('plugin', 'ledger', '1.1.0'));
        $this->assertStringContainsString("is published by northwind in the registry, but the installed version is acme's and the package hasn't moved", implode(' ', $check['problems']));
    }

    public function testADownloadThatIsntTheRegistrysIsThrownAway(): void
    {
        $listed = array_values(array_filter(array_keys($this->registry->served), static fn(string $p): bool => str_starts_with($p, 'dl/ledger/1.0.0/')))[0];
        $real = $this->registry->served[$listed];

        $this->registry->served[$listed] = substr_replace($real, chr(ord($real[100]) ^ 1), 100, 1);
        $this->assertThrows(\Kestello\Cms\Package\Registry\RegistryException::class, fn() => $this->packages()->download('plugin', 'ledger', '1.0.0'));
        $this->registry->served[$listed] = $real . str_repeat('x', 4096);
        $this->assertThrows(\Kestello\Cms\Package\Registry\RegistryException::class, fn() => $this->packages()->download('plugin', 'ledger', '1.0.0'));
        $this->assertSame([], glob($this->root . '/storage/tmp/updates/*') ?: [], 'nothing is left behind');

        $this->registry->served[$listed] = $real;
        $this->assertFileExists($this->packages()->download('plugin', 'ledger', '1.0.0'));
    }

    public function testWhenTheRegistryIsOffOrUnreachableNothingIsVouchedFor(): void
    {
        $zip = $this->packages()->download('plugin', 'ledger', '1.0.0');
        $this->installer()->install($zip, ['require_registry' => true]);
        $this->registry->transport->sent = [];

        // turned off: the zip is any other upload, and registry packages get no updates
        $this->cms->config->set('registry.enabled', false);
        $this->assertSame(RegistryTrust::UNKNOWN, $this->installer()->inspect($zip)['registry']['status']);
        $this->assertNull(PackagesModule::registryPackages($this->cms->container));
        $this->assertSame([], $this->registry->transport->sent, 'not a single request');

        // expired and unreachable: the same, with the reason
        $this->cms->config->set('registry.enabled', true);
        $this->registry->now += 30 * 86_400;
        $verdict = $this->installer()->inspect($zip)['registry'];
        $this->assertSame(RegistryTrust::UNKNOWN, $verdict['status']);
        $this->assertStringContainsString('expired', (string) $verdict['reason']);
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function command(array $args, array $options = []): array
    {
        $out = fopen('php://memory', 'w+');
        $command = new \Kestello\Cms\Console\Package\RegistryInstallCommand();
        $command->setOutput(new \Kestello\Framework\Console\Output($out, false));
        $code = $command->handle($args, $options);
        rewind($out);

        return [$code, (string) stream_get_contents($out)];
    }

    public function testRegistryInstallInstallsTheNewestVersionAndActivatesIt(): void
    {
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.1.0', $this->acme), '1.1.0');
        $this->registry->version('plugin', 'ledger', 'acme', $this->zip('1.2.0-beta.1', $this->acme), '1.2.0-beta.1');
        $this->republish();

        $this->assertSame(1, $this->command(['Not A Name'])[0]);
        [$code, $out] = $this->command(['nothing']);
        $this->assertSame([1, true], [$code, str_contains($out, "doesn't list \"nothing\"")], $out);

        [$code, $out] = $this->command(['ledger'], ['activate' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Installed Ledger 1.1.0, published by Acme (acme).', $out);
        $this->assertSame('1.1.0', $this->installedVersion(), 'the newest stable version');
        $this->assertTrue($this->cms->extensions()->isPluginEnabled('ledger'));
        $this->assertSame('acme', $this->pins()->organisation('plugin:ledger'));
        $this->assertSame([], glob($this->root . '/storage/tmp/updates/*') ?: [], 'the download is removed');

        [$code, $out] = $this->command(['ledger']);
        $this->assertSame([1, true], [$code, str_contains($out, 'is already installed')], $out);
    }

    public function testRegistryInstallRefusesWhatTheRegistryWouldnt(): void
    {
        $this->registry->amend('ledger', '1.0.0', ['withdrawn' => ['at' => RegistryFixture::at($this->registry->now - 60), 'reason' => 'Broken.']]);
        $this->republish();

        [$code, $out] = $this->command(['ledger']);
        $this->assertSame([1, true], [$code, str_contains($out, 'no version of Ledger that this site can run')], $out);
        [$code, $out] = $this->command(['ledger'], ['version' => '1.0.0']);
        $this->assertSame([1, true], [$code, str_contains($out, 'no version 1.0.0 of ledger that this site can install')], $out);
        $this->assertFalse(is_dir($this->root . '/content/plugins/ledger'));
    }

    public function testAdvisoriesAreShownWithTheVersion(): void
    {
        $this->registry->advisories[] = ['id' => 'KSA-2027-0001', 'type' => 'plugin', 'package' => 'ledger', 'affected' => '<1.1.0', 'fixed' => '1.1.0', 'severity' => 'high', 'title' => 'Stored XSS in labels', 'url' => 'https://kestello.com/advisories/KSA-2027-0001', 'published_at' => RegistryFixture::at($this->registry->now - 60)];
        $this->republish();

        $advisories = $this->installer()->inspect($this->packages()->download('plugin', 'ledger', '1.0.0'))['registry']['advisories'];
        $this->assertSame(['KSA-2027-0001'], array_column($advisories, 'id'));
    }

    public function testSiteHealthSaysWhenAnAdvisoryAppliesToWhatTheSiteRuns(): void
    {
        $this->installFromRegistry();
        $this->assertSame(HealthResult::GOOD, PackagesModule::advisories($this->cms)->status, 'nothing applies yet');

        $this->registry->advisories[] = ['id' => 'KSA-2027-0003', 'type' => 'plugin', 'package' => 'ledger', 'affected' => '<1.0.0', 'fixed' => '1.0.0', 'severity' => 'critical', 'title' => 'Not this version', 'url' => 'https://kestello.com/advisories/KSA-2027-0003', 'published_at' => RegistryFixture::at($this->registry->now - 120)];
        $this->registry->advisories[] = ['id' => 'KSA-2027-0001', 'type' => 'plugin', 'package' => 'ledger', 'affected' => '<1.1.0', 'fixed' => '1.1.0', 'severity' => 'medium', 'title' => 'Totals off by one', 'url' => 'https://kestello.com/advisories/KSA-2027-0001', 'published_at' => RegistryFixture::at($this->registry->now - 60)];
        $this->republish();
        $result = PackagesModule::advisories($this->cms);
        $this->assertSame(HealthResult::RECOMMENDED, $result->status, 'medium: recommended');
        $this->assertSame('A security advisory applies to this site. Ledger 1.0.0: Totals off by one (KSA-2027-0001, medium). Update to 1.1.0 or later.', $result->message);
        $this->assertSame(['KSA-2027-0001' => 'Ledger 1.0.0 (medium)'], $result->details);
        $this->assertSame(InstalledAdvisories::ACTION, $result->action);

        $this->registry->advisories[] = ['id' => 'KSA-2027-0002', 'type' => 'core', 'package' => 'core', 'affected' => '<99.0.0', 'fixed' => null, 'severity' => 'high', 'title' => 'Open redirect', 'url' => 'https://kestello.com/advisories/KSA-2027-0002', 'published_at' => RegistryFixture::at($this->registry->now - 30)];
        $this->republish();
        $result = PackagesModule::advisories($this->cms);
        $this->assertSame(HealthResult::CRITICAL, $result->status, 'high: critical, so administrators are alerted');
        $this->assertSame(['KSA-2027-0002', 'KSA-2027-0001'], array_keys($result->details), 'most severe first, Kestello itself included');
        $this->assertStringContainsString('Kestello ' . $this->cms->version() . ': Open redirect (KSA-2027-0002, high). No fixed version yet', $result->message);

        $this->cms->config->set('registry.enabled', false);
        $this->assertStringContainsString('turned off', PackagesModule::advisories($this->cms)->message);
    }

    public function testAnAdvisoryOnlyNamesPackagesInstalledFromTheRegistry(): void
    {
        $this->registry->advisories[] = ['id' => 'KSA-2027-0001', 'type' => 'plugin', 'package' => 'ledger', 'affected' => '<1.1.0', 'fixed' => '1.1.0', 'severity' => 'high', 'title' => 'Stored XSS in labels', 'url' => 'https://kestello.com/advisories/KSA-2027-0001', 'published_at' => RegistryFixture::at($this->registry->now - 60)];
        $this->republish();
        $advisories = $this->cms->container->make(RegistryClient::class)->advisories();
        $ledger = ['type' => 'plugin', 'slug' => 'ledger', 'name' => 'Ledger', 'version' => '1.0.0'];

        $this->assertSame([], InstalledAdvisories::find($advisories, [$ledger + ['from_registry' => false]], '0.1.0'), 'an uploaded zip can share a name with an unrelated package');
        $this->assertSame(['KSA-2027-0001'], array_column(array_column(InstalledAdvisories::find($advisories, [$ledger + ['from_registry' => true]], '0.1.0'), 'advisory'), 'id'));
        $this->assertSame([], InstalledAdvisories::find($advisories, [['version' => '1.1.0'] + $ledger + ['from_registry' => true]], '0.1.0'), 'the fixed version');
    }
}
