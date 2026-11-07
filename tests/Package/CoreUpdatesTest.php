<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Update\CoreRelease;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Tests\Auth\AuthTestCase;

/** Core updates through core/releases/<version> and core/current.php. */
final class CoreUpdatesTest extends AuthTestCase
{
    private string $root;
    /** @var list<string> versions the probe saw */
    public array $booted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        mkdir($this->root . '/core', 0775, true);
        $this->cms->storage->configure($this->root . '/storage/app', []);
        $ext = new ExtensionManager($this->cms, new ArrayStateStore(), new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $ext->boot();
        $this->cms->setExtensions($ext);
        foreach ([new BackupModule(), new PackagesModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        // a fresh boot loads whatever core/current.php names; a release with BROKEN fails after touching the database
        $test = $this;
        $this->cms->container->instance(HealthProbe::class, new class ($test) implements HealthProbe {
            public function __construct(private CoreUpdatesTest $test) {}

            public function run(string $owner, string $bypass): array
            {
                return $this->test->probe();
            }
        });
        $this->createUser();
    }

    /** @return array<string, mixed> */
    public function probe(): array
    {
        $pointer = $this->root . '/core/current.php';
        $version = is_file($pointer) ? (string) (require $pointer) : '0.1.0';
        $this->booted[] = $version;
        if (is_file("{$this->root}/core/releases/{$version}/BROKEN")) {
            $this->db->schema()->create('half_migrated', fn($t) => $t->id());
            return ['probe_error' => 'Core migrations failed: syntax error (2026_11_05_000001_broken.php:3)'];
        }

        return ['owner' => 'core', 'version' => $version, 'enabled' => true, 'running' => true, 'suspended' => null, 'rejected' => [],
            'plugins' => array_keys($this->cms->extensions()->activePlugins()), 'suspensions' => [], 'pages' => ['/' => ['status' => 200, 'error' => null]]];
    }

    private function release(string $version, bool $broken = false, string $php = '>=8.3'): string
    {
        $dir = $this->root . "/build/{$version}/core";
        mkdir($dir . '/src', 0775, true);
        file_put_contents($dir . '/VERSION', $version);
        file_put_contents($dir . '/core.json', json_encode(['requires' => ['php' => $php], 'extensions' => ['json']]));
        file_put_contents($dir . '/bootstrap.php', "<?php\n");
        file_put_contents($dir . '/src/Kernel.php', "<?php\n");
        if ($broken) {
            file_put_contents($dir . '/BROKEN', '1');
        }
        mkdir($dir . '/releases/9.9.9', 0775, true);      // a site's own releases never go into a release
        file_put_contents($dir . '/current.php', "<?php return '9.9.9';");

        return CoreRelease::build($dir, $this->root . '/dist')['zip'];
    }

    private function core(string $running): CoreUpdater
    {
        $c = $this->cms->container;

        return new CoreUpdater($this->cms, $c->make(PackageInstaller::class), $c->make(Backups::class), $c->make(HealthProbe::class), $running);
    }

    private function pointer(): ?string
    {
        $file = $this->root . '/core/current.php';

        return is_file($file) ? (string) (require $file) : null;
    }

    public function testUpdatesSwitchReleasesAndPruneOldOnes(): void
    {
        $this->assertSame('development', $this->core('0.1.0')->status()['mode']);
        $r = $this->core('0.1.0')->update($this->release('0.2.0'), ['allow_unsigned' => true]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('0.2.0', $this->pointer());
        $this->assertSame(['0.2.0'], $this->booted, 'the check booted the new release');
        $this->assertFileExists($this->root . '/core/releases/0.2.0/src/Kernel.php');
        $this->assertFalse(is_dir($this->root . '/core/releases/0.2.0/releases'), 'release folders inside a release are left out');
        $this->assertFalse(is_file($this->root . '/core/releases/0.2.0/current.php'));
        $this->assertNull((new Maintenance($this->root . '/storage'))->current());
        $this->assertContains('before-update', array_column($this->cms->container->make(Backups::class)->all(), 'kind'));
        $status = $this->core('0.2.0')->status();
        $this->assertSame(['releases', [['version' => '0.2.0', 'current' => true]]], [$status['mode'], $status['releases']]);

        foreach (['0.3.0', '0.4.0', '0.5.0'] as $i => $v) {
            touch($this->root . '/core/releases/' . ['0.2.0', '0.3.0', '0.4.0'][$i], time() - 100 + $i);
            $this->assertTrue($this->core(['0.2.0', '0.3.0', '0.4.0'][$i])->update($this->release($v), ['allow_unsigned' => true])['ok']);
        }
        // the running one, the one before it, and one more
        $this->assertSame(['0.5.0', '0.4.0', '0.3.0'], array_column($this->core('0.5.0')->status()['releases'], 'version'));
    }

    public function testABrokenReleaseSwitchesBackAndRestoresTheDatabase(): void
    {
        $this->core('0.1.0')->update($this->release('0.2.0'), ['allow_unsigned' => true]);
        $this->cms->extensions()->suspensions()->suspend('plugin:other', 'from before');
        $r = $this->core('0.2.0')->update($this->release('0.3.0', broken: true), ['allow_unsigned' => true]);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['rolled_back'], json_encode($r));
        $this->assertStringContainsString('Core migrations failed', $r['problems'][0]);
        $this->assertSame('0.2.0', $this->pointer());
        $this->assertSame(['0.2.0', '0.3.0', '0.2.0'], $this->booted, 'checked again after switching back');
        $this->assertFalse(is_dir($this->root . '/core/releases/0.3.0'));
        $this->assertFalse($this->db->schema()->hasTable('half_migrated'), 'what the broken release did to the database is gone');
        $this->assertSame('from before', (new SuspensionList($this->root . '/suspended.json'))->reason('plugin:other'));
        $this->assertNull((new Maintenance($this->root . '/storage'))->current());
    }

    public function testABrokenFirstUpdateLeavesTheDevelopmentCheckoutAsItWas(): void
    {
        $r = $this->core('0.1.0')->update($this->release('0.2.0', broken: true), ['allow_unsigned' => true]);
        $this->assertTrue($r['rolled_back'], json_encode($r));
        $this->assertNull($this->pointer(), 'no pointer: core/ runs directly again');
        $this->assertSame('development', $this->core('0.1.0')->status()['mode']);
    }

    public function testReleasesThatCantBeAppliedChangeNothing(): void
    {
        $core = $this->core('0.2.0');
        $this->assertSame(['Kestello 0.2.0 is already running.'], $core->check($this->release('0.2.0'))['problems']);
        $this->assertStringContainsString('older than the running', $core->check($this->release('0.1.5'))['problems'][0]);
        $this->assertStringContainsString('needs PHP >=99.0', $core->check($this->release('0.3.0', php: '>=99.0'))['problems'][0]);

        // an active plugin that doesn't support the new version blocks it
        $dir = $this->root . '/content/plugins/ledger';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/plugin.json', json_encode(['slug' => 'ledger', 'name' => 'Ledger', 'version' => '1.0.0', 'requires' => ['core' => '^0.1']]));
        $this->cms->extensions()->discover(true);
        $this->cms->extensions()->activatePlugin('ledger');
        $this->assertSame(['The plugin Ledger 1.0.0 supports Kestello ^0.1, not 1.0.0.'], $this->core('0.1.0')->check($this->release('1.0.0'))['problems']);

        // signatures: unsigned needs allow_unsigned; a trusted signature doesn't; tampered never
        $zip = $this->release('0.1.1');
        $this->assertFalse($this->core('0.1.0')->check($zip)['allowed']);
        $this->assertThrows(ContentException::class, fn() => $this->core('0.1.0')->update($zip));
        $keys = PackageSignature::generateKeys();
        PackageSignature::sign($zip, $keys['secret_key']);
        $this->cms->config->set('app.packages.trusted_keys', [['name' => 'Kestello', 'public_key' => $keys['public_key']]]);
        $this->assertSame('signed', $this->core('0.1.0')->check($zip)['signature']['status']);
        $this->assertTrue($this->core('0.1.0')->check($zip)['allowed']);

        // a plugin zip is not a core release
        $plugin = $this->root . '/p.zip';
        $w = \Kestello\Support\Zip\Zip::create($plugin);
        $w->addString('ledger/plugin.json', '{}');
        $w->close();
        $this->assertThrows(ContentException::class, fn() => $this->core('0.1.0')->check($plugin));

        $this->assertNull($this->pointer());
        $this->assertSame([], $this->cms->container->make(Backups::class)->all(), 'nothing was backed up, nothing switched');
    }

    public function testTheAdminUpdatesCoreThroughTheApi(): void
    {
        $this->cms->container->instance(CoreUpdater::class, $this->core('0.1.0'));
        $this->cms->config->set('app.packages.admin_unsigned', true);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame('development', json_decode($this->api('GET', '/updates')->content(), true)['data']['core']['mode']);

        $copy = $this->root . '/up.zip';
        copy($this->release('0.2.0'), $copy);
        $this->nextRequest();
        $review = $this->cms->routes->dispatch(new Request(
            'POST',
            '/api/v1/updates/core/upload',
            '/api/v1/updates/core/upload',
            [],
            [],
            [],
            [],
            ['file' => ['name' => 'core.zip', 'type' => 'application/zip', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        ));
        $data = json_decode($review->content(), true)['data'];
        $this->assertSame(['0.2.0', '0.1.0', [], true], [$data['release']['version'], $data['release']['from'], $data['release']['problems'], $data['release']['allowed']], $review->content());

        $done = $this->api('POST', '/updates/core', ['token' => $data['token']]);
        $this->assertSame(200, $done->statusCode(), $done->content());
        $this->assertTrue(json_decode($done->content(), true)['data']['result']['ok']);
        $this->assertSame('0.2.0', $this->pointer());
    }

    public function testAReleaseFromGitHubSignedWithAReleaseKeyIsTrustedAndApplied(): void
    {
        $keys = PackageSignature::generateKeys();
        $zip = $this->release('0.2.0');
        PackageSignature::sign($zip, $keys['secret_key']);
        $base = 'https://github.com/kestellohq/cms/releases/download/v0.2.0/';
        $transport = new FakeTransport([
            'GET https://api.github.com/repos/kestellohq/cms/releases' => ['json' => [ReleaseFeedTest::release('0.2.0')]],
            "GET {$base}kestello-core-0.2.0.zip.sha256" => ['body' => hash_file('sha256', $zip) . "  kestello-core-0.2.0.zip\n"],
            "GET {$base}kestello-core-0.2.0.zip" => ['body' => (string) file_get_contents($zip)],
        ]);
        $saved = null;
        $c = $this->cms->container;
        $c->instance(UpdateChecker::class, new UpdateChecker(
            new HttpClient($transport, static fn(): array => ['140.82.112.3']),
            ['repository' => 'kestellohq/cms'],
            '0.1.0',
            $this->root . '/storage/tmp/updates',
            static function () use (&$saved): ?array {
                return $saved;
            },
            static function (array $r) use (&$saved): void {
                $saved = $r;
            },
        ));
        // the release key is trusted for core only: app.packages.trusted_keys stays empty
        $c->instance(CoreUpdater::class, new CoreUpdater(
            $this->cms,
            $c->make(PackageInstaller::class),
            $c->make(Backups::class),
            $c->make(HealthProbe::class),
            '0.1.0',
            releaseKeys: UpdateChecker::releaseKeys([['name' => 'Kestello releases', 'public_key' => $keys['public_key']]]),
        ));
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());

        $checked = json_decode($this->api('POST', '/updates/core/check')->content(), true)['data'];
        $this->assertSame('0.2.0', $checked['available']['latest']['version'] ?? null);
        $this->assertStringContainsString('<h3>Fixed</h3>', $checked['available']['latest']['notes_html']);
        $this->assertSame(['Kestello releases'], $checked['policy']['trusted']);

        $review = $this->api('POST', '/updates/core/download', ['version' => '0.2.0']);
        $data = json_decode($review->content(), true)['data'];
        $this->assertSame(['signed', 'Kestello releases', true], [$data['release']['signature']['status'], $data['release']['signature']['name'], $data['release']['allowed']], $review->content());
        $this->assertSame([], glob($this->root . '/storage/tmp/updates/*') ?: [], 'the download is not left behind');

        $done = $this->api('POST', '/updates/core', ['token' => $data['token']]);
        $this->assertTrue(json_decode($done->content(), true)['data']['result']['ok'], $done->content());
        $this->assertSame('0.2.0', $this->pointer());
        $this->assertSame([], (new PackageInstaller($this->cms))->trustedKeys(), 'plugins signed with the release key are not trusted by it');
    }

    public function testADownloadNeedsAVersionGitHubLists(): void
    {
        $this->cms->container->instance(UpdateChecker::class, new UpdateChecker(
            new HttpClient(new FakeTransport(['GET https://api.github.com/repos/kestellohq/cms/releases' => ['json' => []]]), static fn(): array => ['140.82.112.3']),
            ['repository' => 'kestellohq/cms'],
            '0.1.0',
            $this->root . '/storage/tmp/updates',
            static fn(): ?array => null,
            static function (array $r): void {},
        ));
        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);

        $this->assertSame(422, $this->api('POST', '/updates/core/download', ['version' => '../../x'])->statusCode());
        $missing = $this->api('POST', '/updates/core/download', ['version' => '0.2.0']);
        $this->assertSame(409, $missing->statusCode(), $missing->content());
        $this->assertStringContainsString('is not among the releases', $missing->content());
    }
}
