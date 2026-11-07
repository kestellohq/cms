<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\HttpProbe;
use Kestello\Cms\Package\Update\UpdateProbe;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Tests\Auth\AuthTestCase;

/** The update pipeline for plugins: backup, swap, health check, commit or roll back. */
final class UpdatesTest extends AuthTestCase
{
    private string $root;
    private ArrayStateStore $state;
    /** @var list<array<string, mixed>> */
    public array $probes = [];

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
        // the real probe boots a fresh process; here a fresh ExtensionManager over the same state
        $test = $this;
        $this->cms->container->instance(HealthProbe::class, new class ($test) implements HealthProbe {
            public function __construct(private UpdatesTest $test) {}

            public function run(string $owner, string $bypass): array
            {
                $this->test->boot();
                $report = UpdateProbe::report($this->test->cms(), $owner, pages: false);
                $this->test->probes[] = $report + ['bypass' => $bypass];

                return $report;
            }
        });
        $this->cms->config->set('app.packages.admin_unsigned', true);

        $this->release('1.0.0');
        $this->cms->extensions()->install($this->root . '/src/ledger', PackageType::Plugin);
        $this->cms->extensions()->activatePlugin('ledger');
        $this->db->table('p_ledger_lines')->insert(['label' => 'kept through updates']);
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
     * Write version $version of the "ledger" plugin to src/ledger.
     *
     * @param array<string, string> $migrations name => up() body
     */
    private function release(string $version, array $migrations = [], array $manifest = []): void
    {
        $dir = $this->root . '/src/ledger';
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        mkdir($dir . '/database/migrations', 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode($manifest + ['slug' => 'ledger', 'name' => 'Ledger', 'version' => $version, 'requires' => ['core' => '^0.1']]));
        $migrations = ['2026_01_01_000001_lines.php' => "\$this->schema()->create('p_ledger_lines', function (\\Kestello\\Framework\\Database\\Blueprint \$t): void { \$t->id(); \$t->string('label', 80); });"] + $migrations;
        foreach ($migrations as $name => $up) {
            file_put_contents("{$dir}/database/migrations/{$name}", "<?php\nreturn new class extends \\Kestello\\Framework\\Database\\Migration {\n    public function up(): void { {$up} }\n    public function down(): void {}\n};\n");
        }
        file_put_contents($dir . '/VERSION.txt', $version);
    }

    private function zip(): string
    {
        return PackageBuilder::build($this->root . '/src/ledger', $this->root . '/dist')['zip'];
    }

    private function updater(): Updater
    {
        return $this->cms->container->make(Updater::class);
    }

    private function installedVersion(): string
    {
        return trim((string) file_get_contents($this->root . '/content/plugins/ledger/VERSION.txt'));
    }

    public function testAPluginReplacedWhileOffIsUpgradedWhenItIsTurnedOn(): void
    {
        $this->cms->extensions()->deactivatePlugin('ledger');
        $this->release('1.1.0', manifest: ['namespace' => 'Ledger\\', 'provider' => 'Ledger\\Plugin']);
        mkdir($this->root . '/src/ledger/src');
        file_put_contents($this->root . '/src/ledger/src/Plugin.php', "<?php\nnamespace Ledger;\nfinal class Plugin extends \\Kestello\\Cms\\Extension\\PluginProvider {\n    public function upgrade(\\Kestello\\Cms\\Cms \$cms, string \$from, string \$to): void { \\Kestello\\Framework\\Database\\QueryBuilder::table('p_ledger_lines')->insert(['label' => \"upgraded {\$from} to {\$to}\"]); }\n}\n");
        $this->cms->extensions()->install($this->root . '/src/ledger', PackageType::Plugin, overwrite: true);
        $this->cms->extensions()->discover(true);
        $this->cms->extensions()->activatePlugin('ledger');

        $this->assertSame(1, $this->db->table('p_ledger_lines')->where('label', 'upgraded 1.0.0 to 1.1.0')->count());
        $this->assertSame('1.1.0', $this->state->get(PackageType::Plugin, 'ledger')?->version);
    }

    public function testAGoodUpdateRunsItsMigrationsAndKeepsData(): void
    {
        $this->release('1.1.0', ['2026_02_01_000001_notes.php' => "\$this->schema()->create('p_ledger_notes', fn(\\Kestello\\Framework\\Database\\Blueprint \$t) => \$t->id());"]);
        $r = $this->updater()->update($this->zip(), ['allow_unsigned' => true]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['1.0.0', '1.1.0'], [$r['from'], $r['to']]);
        $this->assertSame('1.1.0', $this->installedVersion());
        $this->assertTrue($this->db->schema()->hasTable('p_ledger_notes'));
        $this->assertSame(1, $this->db->table('p_ledger_lines')->count());
        $this->assertSame('1.1.0', $this->state->get(PackageType::Plugin, 'ledger')?->version);
        $this->assertNull((new Maintenance($this->root . '/storage'))->current(), 'maintenance is off');
        // the probe ran during maintenance, with a token that passes it
        $this->assertSame(32, strlen($this->probes[0]['bypass']));
        // a database backup was taken first; the old folder is kept
        $this->assertContains('before-update', array_column($this->cms->container->make(Backups::class)->all(), 'kind'));
        $this->assertCount(1, glob($this->root . '/storage/app/backups/packages/ledger-1.0.0-*', GLOB_ONLYDIR));
    }

    public function testTheActiveThemeUpdates(): void
    {
        $theme = fn(string $v) => (function () use ($v): string {
            $dir = $this->root . '/src/look';
            is_dir($dir) || mkdir($dir, 0777, true);
            file_put_contents($dir . '/theme.json', json_encode(['slug' => 'look', 'name' => 'Look', 'version' => $v, 'requires' => ['core' => '^0.1']]));

            return $dir;
        })();
        $this->cms->extensions()->install($theme('1.0.0'), PackageType::Theme);
        $this->cms->extensions()->activateTheme('look');
        $this->boot();

        $theme('1.1.0');
        $r = $this->updater()->update(PackageBuilder::build($this->root . '/src/look', $this->root . '/dist')['zip'], ['allow_unsigned' => true]);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('1.1.0', $this->state->get(PackageType::Theme, 'look')?->version, 'the recorded version moves with the files');
    }

    public function testABrokenUpdateRollsBackByItself(): void
    {
        // 1.1.0's new migration fails half way: it creates a table, then throws
        $this->release('1.1.0', ['2026_02_01_000001_broken.php' => "\$this->schema()->create('p_ledger_half', fn(\\Kestello\\Framework\\Database\\Blueprint \$t) => \$t->id()); throw new \\RuntimeException('column already exists');"]);
        $r = $this->updater()->update($this->zip(), ['allow_unsigned' => true]);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['rolled_back'], json_encode($r));
        $this->assertStringContainsString('column already exists', implode(' ', $r['problems']));
        // files, database, state and activation as they were
        $this->assertSame('1.0.0', $this->installedVersion());
        $this->assertFalse($this->db->schema()->hasTable('p_ledger_half'), 'the half-done migration is gone with the restore');
        $this->assertSame(1, $this->db->table('p_ledger_lines')->count());
        $this->boot();
        $this->assertSame('1.0.0', $this->state->get(PackageType::Plugin, 'ledger')?->version);
        $this->assertTrue(isset($this->cms->extensions()->activePlugins()['ledger']), 'it runs again');
        $this->assertNull($this->cms->extensions()->suspensions()->reason('plugin:ledger'));
        $this->assertNull((new Maintenance($this->root . '/storage'))->current());
    }

    public function testUpdatesThatCantStartChangeNothing(): void
    {
        $this->release('1.0.0');
        $this->assertSame(['Ledger 1.0.0 is already installed.'], $this->updater()->check($this->zip())['problems']);
        $this->release('0.9.0');
        $this->assertStringContainsString('older than the installed', $this->updater()->check($this->zip())['problems'][0]);
        $this->release('2.0.0', manifest: ['requires' => ['core' => '^9.0']]);
        $this->assertStringContainsString('requires Kestello ^9.0', implode(' ', $this->updater()->check($this->zip())['problems']));

        try {
            $this->updater()->update($this->zip(), ['allow_unsigned' => true]);
            $this->fail('an incompatible update started');
        } catch (ContentException $e) {
            $this->assertStringContainsString('^9.0', implode(' ', $e->errors['file']));
        }
        // unsigned packages need allow_unsigned (the admin's policy), tampered never
        $this->release('1.2.0');
        $this->assertThrows(ContentException::class, fn() => $this->updater()->update($this->zip()));
        $this->assertSame('1.0.0', $this->installedVersion());
        $this->assertSame([], $this->cms->container->make(Backups::class)->all(), 'no backup was even taken');
    }

    public function testTheProbeRouteAnswersOnlyDuringAnUpdate(): void
    {
        $get = fn(array $headers): int => $this->cms->routes->dispatch(new Request('GET', '/system/update/probe', '/system/update/probe', $headers, ['owner' => 'plugin:ledger'], [], [], [], [], ''))->statusCode();
        $this->assertSame(404, $get([]));
        $m = new Maintenance($this->root . '/storage');
        $token = $m->on();
        $this->assertSame(404, $get(['X-Kestello-Bypass' => 'wrong']));
        $this->assertSame(200, $get(['X-Kestello-Bypass' => $token]));
        $m->off();
        $this->assertSame(404, $get(['X-Kestello-Bypass' => $token]));
    }

    public function testTheHttpProbeForHostsWithoutProcesses(): void
    {
        $report = ['owner' => 'plugin:ledger', 'version' => '1.1.0', 'enabled' => true, 'running' => true, 'suspended' => null, 'rejected' => []];
        $fake = new FakeTransport([
            'GET https://site.test/' => ['status' => 200, 'body' => 'home'],
            'GET https://site.test/api/v1/status' => ['status' => 200, 'json' => []],
            'GET https://site.test/system/update/probe' => ['status' => 200, 'json' => $report],
        ]);
        $probe = new HttpProbe(new HttpClient($fake, static fn(): array => ['10.0.0.5']), 'https://site.test/');
        $result = $probe->run('plugin:ledger', 'tok123');
        $this->assertSame([], UpdateProbe::problems($result, '1.1.0', true));
        foreach ($fake->sent as $sent) {
            $this->assertSame('tok123', $sent->headers['X-Kestello-Bypass'] ?? null, 'every check passes maintenance with the token');
        }
        $this->assertStringContainsString('owner=plugin%3Aledger', $fake->sent[0]->url);

        // a broken home page fails the check; an unreachable site says where to point it
        $fake->on('GET https://site.test/', ['status' => 500, 'body' => 'oops']);
        $this->assertSame(['/ answered 500.'], UpdateProbe::problems($probe->run('plugin:ledger', 'tok123'), '1.1.0', true));
        $none = new HttpProbe(new HttpClient(new FakeTransport(), static fn(): array => ['10.0.0.5']), 'https://site.test');
        $this->assertStringContainsString('app.updates.health_url', UpdateProbe::problems($none->run('plugin:ledger', 't'), '1.1.0', true)[0]);
    }

    public function testTheAdminUpdatesThroughTheApi(): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $this->release('1.1.0');
        $zip = $this->zip();
        $copy = $this->root . '/up.zip';
        copy($zip, $copy);
        $this->nextRequest();
        $review = $this->cms->routes->dispatch(new Request(
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
        $data = json_decode($review->content(), true)['data'];
        $this->assertSame(['from' => '1.0.0', 'problems' => []], $data['update']);

        $done = $this->api('POST', '/plugins/update', ['token' => $data['token']]);
        $this->assertSame(200, $done->statusCode(), $done->content());
        $result = json_decode($done->content(), true)['data']['result'];
        $this->assertTrue($result['ok']);
        $this->assertSame('1.1.0', $this->installedVersion());
    }
}
