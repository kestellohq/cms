<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\DatabaseStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Update\CorePointer;
use Kestello\Cms\Package\Update\CoreRelease;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\Files;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\UpdateHistory;
use Kestello\Cms\Package\Update\UpdateJournal;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Cms\Package\Update\UpdateRecovery;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Kernel;
use Kestello\Support\AppKey;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * Updates that PHP stops halfway, and switching back after one finished.
 *
 * The "killed" tests run the update in a child process and kill it with
 * SIGKILL during the health check: nothing in it gets to clean up. The
 * site and its SQLite file are shared with this process, which then does
 * what the next request would.
 */
final class UpdateRecoveryTest extends AuthTestCase
{
    private string $root;
    private string $dbFile;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        mkdir($this->root . '/core', 0775, true);
        file_put_contents($this->root . '/core/bootstrap.php', "<?php\n");      // core/ runs directly: a development checkout

        // a database file both processes open
        $this->dbFile = $this->root . '/site.sqlite';
        $this->db = new Connection(['driver' => 'sqlite', 'database' => $this->dbFile]);
        DatabaseManager::setConnection($this->db);
        \Kestello\Framework\Settings\Settings::destroy();
        (new MigrationRunner(dirname(__DIR__, 2) . '/core/database/migrations'))->migrate();
        $this->key = bin2hex(random_bytes(32));
        AppKey::set($this->key);

        $this->cms->storage->configure($this->root . '/storage/app', []);
        $this->boot();
        foreach ([new BackupModule(), new PackagesModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        // a healthy check: whatever core/current.php names right now
        $test = $this;
        $this->cms->container->instance(HealthProbe::class, new class ($test) implements HealthProbe {
            public function __construct(private UpdateRecoveryTest $test) {}

            public function run(string $owner, string $bypass): array
            {
                return $this->test->healthy($owner);
            }
        });
        $this->cms->config->set('app.packages.admin_unsigned', true);
        $this->createUser();
    }

    public function boot(): void
    {
        $ext = new ExtensionManager($this->cms, new DatabaseStateStore(), new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $ext->boot();
        $this->cms->setExtensions($ext);
    }

    /** @return array<string, mixed> */
    public function healthy(string $owner): array
    {
        $this->boot();
        if ($owner !== 'core') {
            return \Kestello\Cms\Package\Update\UpdateProbe::report($this->cms, $owner, pages: false);
        }

        return ['owner' => 'core', 'version' => CorePointer::read($this->pointerFile()) ?? '0.1.0', 'enabled' => true, 'running' => true, 'suspended' => null, 'rejected' => [],
            'plugins' => array_keys($this->cms->extensions()->activePlugins()), 'suspensions' => [], 'pages' => ['/' => ['status' => 200, 'error' => null]]];
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function coreRelease(string $version): string
    {
        $dir = $this->root . "/build/{$version}/core";
        mkdir($dir . '/src', 0775, true);
        file_put_contents($dir . '/VERSION', $version);
        file_put_contents($dir . '/core.json', json_encode(['requires' => ['php' => '>=8.3'], 'extensions' => ['json']]));
        file_put_contents($dir . '/bootstrap.php', "<?php\n");
        file_put_contents($dir . '/src/Kernel.php', "<?php\n");

        return CoreRelease::build($dir, $this->root . '/dist')['zip'];
    }

    private function core(string $running): CoreUpdater
    {
        $c = $this->cms->container;
        $core = new CoreUpdater($this->cms, $c->make(PackageInstaller::class), $c->make(Backups::class), $c->make(HealthProbe::class), $running);
        $c->instance(CoreUpdater::class, $core);

        return $core;
    }

    private function recovery(): UpdateRecovery
    {
        return $this->cms->container->make(UpdateRecovery::class);
    }

    private function journal(): UpdateJournal
    {
        return new UpdateJournal($this->root . '/storage');
    }

    private function pointerFile(): string
    {
        return $this->root . '/core/current.php';
    }

    /** Version $version of the "ledger" plugin, as a zip. */
    private function ledger(string $version): string
    {
        $dir = $this->root . "/src/{$version}/ledger";
        mkdir($dir . '/database/migrations', 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode(['slug' => 'ledger', 'name' => 'Ledger', 'version' => $version, 'requires' => ['core' => '^0.1']]));
        file_put_contents($dir . '/database/migrations/2026_01_01_000001_lines.php', "<?php\nreturn new class extends \\Kestello\\Framework\\Database\\Migration {\n    public function up(): void { \$this->schema()->create('p_ledger_lines', function (\\Kestello\\Framework\\Database\\Blueprint \$t): void { \$t->id(); \$t->string('label', 80); }); }\n    public function down(): void {}\n};\n");
        file_put_contents($dir . '/VERSION.txt', $version);

        return PackageBuilder::build($dir, $this->root . "/dist/{$version}")['zip'];
    }

    private function installLedger(): void
    {
        $this->ledger('1.0.0');
        $this->cms->extensions()->install($this->root . '/src/1.0.0/ledger', PackageType::Plugin);
        $this->cms->extensions()->activatePlugin('ledger');
        $this->db->table('p_ledger_lines')->insert(['label' => 'from before the update']);
    }

    private function ledgerVersion(): string
    {
        return trim((string) file_get_contents($this->root . '/content/plugins/ledger/VERSION.txt'));
    }

    /** Run the update in a child process and kill it during the health check. */
    private function killDuringUpdate(string $kind, string $zip, string $running = '0.1.0'): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/die-during-update.php', $kind, $this->root, $this->dbFile, $zip, $running],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['KESTELLO_TEST_KEY' => $this->key] + getenv(),
        );
        $this->assertTrue(is_resource($process));
        do {
            usleep(20_000);
            $status = proc_get_status($process);
        } while ($status['running']);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);
        $this->assertTrue($status['signaled'] && $status['termsig'] === 9, 'the update was killed, not finished: ' . $out);
        $this->db->forgetColumns();
    }

    // ── killed halfway ──────────────────────────────────────────────

    public function testACoreUpdateKilledDuringItsCheckRunsTheOldReleaseAndIsPutBack(): void
    {
        $this->assertTrue($this->core('0.1.0')->update($this->coreRelease('0.2.0'), ['allow_unsigned' => true])['ok']);
        $this->db->table('settings')->insert(['key' => 'site.marker', 'value' => 'before 0.3.0', 'group' => 'test']);

        $this->killDuringUpdate('core', $this->coreRelease('0.3.0'), '0.2.0');

        // what the next request finds
        $this->assertSame(UpdateJournal::SWITCHING, $this->journal()->pending()['step'] ?? null);
        $this->assertFalse(Files::lockHeld($this->root . '/storage'), 'the dead process let go of the lock');
        $this->assertSame('0.2.0', CorePointer::read($this->pointerFile()), 'the next request already loads the old release');
        $this->assertTrue($this->db->schema()->hasTable('half_migrated'));
        $this->assertNotNull((new Maintenance($this->root . '/storage'))->current(), 'visitors are still kept out');

        $r = $this->recovery()->recover();

        $this->assertSame(['ok' => true, 'event' => 'put_back', 'owner' => 'core', 'from' => '0.2.0', 'to' => '0.3.0'], array_intersect_key($r, array_flip(['ok', 'event', 'owner', 'from', 'to'])), json_encode($r));
        $this->assertSame(CorePointer::plain('0.2.0'), file_get_contents($this->pointerFile()), 'a plain pointer again');
        $this->assertFalse(is_dir($this->root . '/core/releases/0.3.0'));
        $this->assertTrue(is_dir($this->root . '/core/releases/0.2.0'));
        $this->db->forgetColumns();
        $this->assertFalse($this->db->schema()->hasTable('half_migrated'), 'the database is as it was before the update');
        $this->assertSame('before 0.3.0', $this->db->table('settings')->where('key', 'site.marker')->value('value'));
        $this->assertNull((new Maintenance($this->root . '/storage'))->current());
        $this->assertNull($this->journal()->current());
        $this->assertSame([], $this->core('0.2.0')->check($this->coreRelease('0.3.1'))['problems'], 'the next update can go ahead');
    }

    public function testTheFirstCoreUpdateOfACheckoutKilledHalfwayFallsBackToCoreItself(): void
    {
        $this->killDuringUpdate('core', $this->coreRelease('0.2.0'));

        $this->assertNull(CorePointer::read($this->pointerFile()), 'the trial pointer names core/ itself (releases/..)');
        $this->assertSame('..', (static fn(string $f): mixed => require $f)($this->pointerFile()));

        $this->assertTrue($this->recovery()->recover()['ok']);
        $this->assertFileDoesNotExist($this->pointerFile());
        $this->assertSame('development', $this->core('0.1.0')->status()['mode']);
    }

    public function testAPluginUpdateKilledDuringItsCheckGetsItsOldFolderAndDatabaseBack(): void
    {
        $this->installLedger();
        $zip = $this->ledger('1.1.0');

        $this->killDuringUpdate('plugin', $zip);

        $this->assertSame('1.1.0', $this->ledgerVersion(), 'the new code was in place when PHP stopped');
        $this->assertSame('plugin:ledger', $this->journal()->pending()['owner'] ?? null);
        $this->assertTrue($this->db->schema()->hasTable('half_migrated'));

        $r = $this->recovery()->recover();

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('1.0.0', $this->ledgerVersion());
        $this->assertSame([], glob($this->root . '/storage/app/backups/packages/ledger-*') ?: [], 'the old folder went back where it was');
        $this->db->forgetColumns();
        $this->assertFalse($this->db->schema()->hasTable('half_migrated'));
        $this->assertSame(['from before the update'], array_column($this->db->table('p_ledger_lines')->get(), 'label'));
        $this->assertNull((new Maintenance($this->root . '/storage'))->current());
    }

    // ── other places it can stop ────────────────────────────────────

    public function testStoppedBeforeTheSwitchOnlyCleansUpAndKeepsTheDatabase(): void
    {
        $backup = $this->cms->container->make(Backups::class)->create(['files' => false, 'kind' => 'before-update'])['name'];
        mkdir($this->root . '/core/releases/0.2.0/src', 0775, true);       // half unpacked
        $this->journal()->begin(['kind' => 'update', 'owner' => 'core', 'from' => '0.1.0', 'to' => '0.2.0', 'backup' => $backup, 'target' => $this->root . '/core/releases/0.2.0']);
        $this->db->table('settings')->insert(['key' => 'site.after', 'value' => 'written after the backup', 'group' => 'test']);

        $r = $this->recovery()->recover();

        $this->assertSame('put_back', $r['event'], json_encode($r));
        $this->assertFalse(is_dir($this->root . '/core/releases/0.2.0'));
        $this->assertSame('written after the backup', $this->db->table('settings')->where('key', 'site.after')->value('value'), 'nothing had changed the database: it is not restored');
    }

    public function testStoppedAfterTheCheckPassedKeepsTheNewVersion(): void
    {
        $this->assertTrue($this->core('0.1.0')->update($this->coreRelease('0.2.0'), ['allow_unsigned' => true])['ok']);
        mkdir($this->root . '/core/releases/0.3.0', 0775, true);
        file_put_contents($this->root . '/core/releases/0.3.0/bootstrap.php', "<?php\n");
        $backup = $this->cms->container->make(Backups::class)->create(['files' => false, 'kind' => 'before-update'])['name'];
        $this->journal()->begin(['kind' => 'update', 'owner' => 'core', 'from' => '0.2.0', 'to' => '0.3.0', 'backup' => $backup, 'previous' => '0.2.0', 'target' => $this->root . '/core/releases/0.3.0'], UpdateJournal::CONFIRMED);
        CorePointer::write($this->pointerFile(), CorePointer::trial('0.3.0', '0.2.0', Files::lockFile($this->root . '/storage')));
        $this->db->table('settings')->insert(['key' => 'site.after', 'value' => 'kept', 'group' => 'test']);

        $r = $this->recovery()->recover();

        $this->assertSame('finished', $r['event'], json_encode($r));
        $this->assertSame(CorePointer::plain('0.3.0'), file_get_contents($this->pointerFile()));
        $this->assertSame('kept', $this->db->table('settings')->where('key', 'site.after')->value('value'));
        $this->assertSame('0.2.0', $this->recovery()->switchBackOption('core', '0.3.0')['to'] ?? null, 'and it can be switched back like any other');
    }

    public function testARunningUpdateIsNeverMistakenForAStoppedOne(): void
    {
        $storage = $this->root . '/storage';
        $this->journal()->begin(['kind' => 'update', 'owner' => 'core', 'from' => '0.1.0', 'to' => '0.2.0', 'backup' => 'x', 'target' => 'y'], UpdateJournal::SWITCHING);
        CorePointer::write($this->pointerFile(), CorePointer::trial('0.2.0', '0.1.9', Files::lockFile($storage)));

        Files::locked($storage, function () use ($storage): void {
            $this->assertTrue(Files::lockHeld($storage));
            $this->assertNull($this->journal()->pending(), 'its own lock: still running');
            $this->assertSame('0.2.0', (static fn(string $f): mixed => require $f)($this->pointerFile()), 'the check sees the new release');
            $this->assertThrows(ContentException::class, fn() => $this->recovery()->recover(), 'and nothing else touches it');
        });

        $this->assertSame('0.1.9', (static fn(string $f): mixed => require $f)($this->pointerFile()), 'the lock is gone: the old release');
        $this->assertNotNull($this->journal()->pending());
    }

    public function testRecoveryThatKeepsFailingStopsTryingAndBlocksUpdatesUntilSomeoneActs(): void
    {
        $this->installLedger();
        $this->journal()->begin(['kind' => 'update', 'owner' => 'plugin:ledger', 'from' => '1.0.0', 'to' => '1.1.0', 'backup' => 'kestello-backup-before-update-20260101-000000-abcdef.zip',
            'target' => $this->root . '/content/plugins/ledger', 'aside' => null], UpdateJournal::SWITCHING);

        foreach ([1, 2, 3] as $attempt) {
            $r = $this->recovery()->recover();
            $this->assertFalse($r['ok']);
            $this->assertSame($attempt, $r['attempts']);
            $this->assertStringContainsString('is missing', $r['problems'][0]);
        }
        $this->assertNull($this->journal()->pending(), 'it stops trying by itself');
        $this->assertTrue((int) $this->journal()->stopped()['attempts'] >= UpdateJournal::MAX_ATTEMPTS);
        $maintenance = (new Maintenance($this->root . '/storage'))->current();
        $this->assertSame(Maintenance::VISITORS, $maintenance['mode'] ?? null, 'visitors stay out, administrators can sign in');
        $this->assertTrue($maintenance['expires_at'] > time() + 86400, 'for days, not half an hour');

        $this->assertSame([UpdateRecovery::BLOCKED], $this->cms->container->make(Updater::class)->check($this->ledger('1.2.0'))['problems']);
        $this->assertSame([UpdateRecovery::BLOCKED], $this->core('0.1.0')->check($this->coreRelease('0.2.0'))['problems']);

        (new \Kestello\Cms\Health\HealthModule())->boot($this->cms);
        $check = $this->cms->health->run(fresh: true, only: ['core.update-stopped'])['checks'][0];
        $this->assertSame(['critical', '/admin/system/updates'], [$check['status'], $check['action']['url'] ?? null], json_encode($check));
        $this->assertStringContainsString('The update of Ledger from 1.0.0 to 1.1.0', $check['message'] ?? '', 'the name people know, not the folder');

        $this->journal()->retry();
        $this->assertNotNull($this->journal()->pending(), 'Try again: the next request tries again');
        $this->assertTrue($this->recovery()->journal()->current() !== null);
        $this->journal()->clear();
        $this->assertSame('good', $this->cms->health->run(fresh: true, only: ['core.update-stopped'])['checks'][0]['status']);
    }

    public function testAJournalNamingOtherFoldersMovesNothing(): void
    {
        $this->installLedger();
        $backup = $this->cms->container->make(Backups::class)->create(['files' => false, 'kind' => 'before-update'])['name'];
        mkdir($elsewhere = $this->root . '/elsewhere', 0775, true);
        file_put_contents($elsewhere . '/plugin.json', '{}');
        $this->journal()->begin(['kind' => 'update', 'owner' => 'plugin:ledger', 'from' => '1.0.0', 'to' => '1.1.0', 'backup' => $backup,
            'target' => $this->root . '/content/plugins/ledger', 'aside' => $elsewhere], UpdateJournal::SWITCHING);

        $r = $this->recovery()->recover();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('are not plugin:ledger', $r['problems'][0]);
        $this->assertSame('1.0.0', $this->ledgerVersion(), 'the installed plugin is untouched');
        $this->assertFileExists($elsewhere . '/plugin.json');
    }

    // ── switching back ──────────────────────────────────────────────

    public function testSwitchingCoreBackRunsTheOldReleaseWithItsDatabase(): void
    {
        $this->assertTrue($this->core('0.1.0')->update($this->coreRelease('0.2.0'), ['allow_unsigned' => true])['ok']);
        $this->assertSame('0.1.0', $this->recovery()->switchBackOption('core', '0.2.0')['to'] ?? null, 'a checkout can go back to running core/ itself');
        $this->assertTrue($this->core('0.2.0')->update($this->coreRelease('0.3.0'), ['allow_unsigned' => true])['ok']);
        $this->db->table('settings')->insert(['key' => 'site.after', 'value' => 'written on 0.3.0', 'group' => 'test']);

        $option = $this->recovery()->switchBackOption('core', '0.3.0');
        $this->assertSame(['core', '0.3.0', '0.2.0'], [$option['owner'], $option['from'], $option['to']]);
        $this->assertNull($this->recovery()->switchBackOption('core', '0.2.0'), 'only from the version the update brought');

        $started = $this->recovery()->switchBack('core', '0.3.0');
        $this->assertSame(CorePointer::plain('0.2.0'), file_get_contents($this->pointerFile()), 'the next request runs 0.2.0');
        $this->assertNotNull($this->journal()->pending(), 'and puts the database back');
        $this->assertContains($started['safety'], array_column($this->cms->container->make(Backups::class)->all(), 'name'));

        $r = $this->recovery()->recover();

        $this->assertSame(['switched_back', '0.2.0', '0.3.0'], [$r['event'], $r['from'], $r['to']], json_encode($r));
        $this->assertNull($this->db->table('settings')->where('key', 'site.after')->value('value'), 'the database from before the update');
        $this->assertTrue(is_dir($this->root . '/core/releases/0.3.0'), 'the newer release stays on the server');
        $this->assertNull($this->recovery()->switchBackOption('core', '0.2.0'), 'there is no switching back twice');
        $this->assertSame('switched-back', (new UpdateHistory($this->root . '/storage'))->all()[0]['event']);
    }

    public function testThePluginsScreenSwitchesAPluginBack(): void
    {
        $this->installLedger();
        $this->assertTrue($this->cms->container->make(Updater::class)->update($this->ledger('1.1.0'), ['allow_unsigned' => true])['ok']);
        $this->db->table('p_ledger_lines')->insert(['label' => 'written on 1.1.0']);

        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $list = json_decode($this->api('GET', '/plugins')->content(), true)['data'];
        $ledger = array_values(array_filter($list['plugins'], static fn(array $p): bool => $p['slug'] === 'ledger'))[0];
        $this->assertSame('1.0.0', $ledger['switch_back']['to'] ?? null, json_encode($ledger));
        $this->assertStringNotContainsString($this->root, json_encode($ledger['switch_back']), 'no server paths in the answer');

        $this->assertSame(422, $this->api('POST', '/plugins/switch-back', ['owner' => 'plugin:../../etc'])->statusCode());
        $this->assertSame(422, $this->api('POST', '/plugins/switch-back', ['owner' => 'core'])->statusCode());
        $this->assertSame(404, $this->api('POST', '/plugins/switch-back', ['owner' => 'plugin:nope'])->statusCode());
        $done = $this->api('POST', '/plugins/switch-back', ['owner' => 'plugin:ledger']);
        $this->assertSame(200, $done->statusCode(), $done->content());
        $this->assertSame(409, $this->api('POST', '/plugins/switch-back', ['owner' => 'plugin:ledger'])->statusCode(), 'one at a time');

        $this->assertTrue($this->recovery()->recover()['ok']);
        $this->assertSame('1.0.0', $this->ledgerVersion());
        $this->assertSame(['from before the update'], array_column($this->db->table('p_ledger_lines')->get(), 'label'));
    }

    public function testTheThemesScreenSwitchesADeveloperThemeBack(): void
    {
        $theme = function (string $v): string {
            $dir = $this->root . "/src/theme-{$v}/look";
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/theme.json', json_encode(['slug' => 'look', 'name' => 'Look', 'version' => $v, 'requires' => ['core' => '^0.1']]));
            file_put_contents($dir . '/functions.php', "<?php\n");

            return $dir;
        };
        $this->cms->extensions()->install($theme('1.0.0'), PackageType::Theme);
        $this->assertTrue($this->cms->container->make(Updater::class)->update(PackageBuilder::build($theme('1.1.0'), $this->root . '/dist/look')['zip'], ['allow_unsigned' => true])['ok']);

        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $themes = json_decode($this->api('GET', '/themes')->content(), true)['data']['themes'];
        $look = array_values(array_filter($themes, static fn(array $t): bool => $t['slug'] === 'look'))[0];
        $this->assertSame(['1.1.0', '1.0.0'], [$look['version'], $look['switch_back']['to'] ?? null], json_encode($look));
        $this->assertStringNotContainsString($this->root, json_encode($look['switch_back']), 'no server paths in the answer');

        $done = $this->api('POST', '/plugins/switch-back', ['owner' => 'theme:look']);
        $this->assertSame(200, $done->statusCode(), $done->content());
        $this->assertTrue($this->recovery()->recover()['ok']);
        $this->assertSame('1.0.0', json_decode((string) file_get_contents($this->root . '/content/themes/look/theme.json'), true)['version']);
        $this->assertNull($this->recovery()->switchBackOption('theme:look', '1.0.0'), 'nothing further back');
    }

    public function testOnlyPeopleWhoMayUpdateCanSwitchBackOrRetry(): void
    {
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());

        $this->assertSame(403, $this->api('POST', '/updates/core/switch-back')->statusCode());
        $this->assertSame(403, $this->api('POST', '/updates/recover')->statusCode());
        $this->assertSame(403, $this->api('POST', '/plugins/switch-back', ['owner' => 'plugin:ledger'])->statusCode());
    }

    public function testTheUpdatesScreenShowsAStoppedUpdateAndTriesAgain(): void
    {
        $this->journal()->begin(['kind' => 'update', 'owner' => 'core', 'from' => '0.1.0', 'to' => '0.2.0', 'backup' => 'x', 'target' => $this->root . '/core/releases/0.2.0'], UpdateJournal::SWITCHING);
        $this->journal()->giveUp();
        $this->core('0.1.0');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());

        $data = json_decode($this->api('GET', '/updates')->content(), true)['data'];
        $this->assertSame(['core', 'Kestello', '0.2.0', true], [$data['stopped_update']['owner'], $data['stopped_update']['name'], $data['stopped_update']['to'], $data['stopped_update']['gave_up']]);
        $this->assertStringNotContainsString($this->root, json_encode($data['stopped_update']));
        $this->assertSame(409, $this->api('POST', '/updates/core/switch-back')->statusCode());

        $this->assertSame(200, $this->api('POST', '/updates/recover')->statusCode());
        $this->assertSame(0, $this->journal()->pending()['attempts'] ?? null);
    }

    public function testTheOldFolderJustSetAsideIsKeptWhateverItsDate(): void
    {
        $this->installLedger();
        touch($this->root . '/content/plugins/ledger', time() - 86400 * 365);    // installed long ago: renaming keeps that date
        foreach (['0.7.0', '0.8.0', '0.9.0'] as $old) {
            mkdir($dir = $this->root . "/storage/app/backups/packages/ledger-{$old}-20260101-000000-abcdef", 0700, true);
        }

        $this->assertTrue($this->cms->container->make(Updater::class)->update($this->ledger('1.1.0'), ['allow_unsigned' => true])['ok']);

        $this->assertSame('1.0.0', $this->recovery()->switchBackOption('plugin:ledger', '1.1.0')['to'] ?? null);
    }

    public function testACheckThatOutlivesItsUpdaterIsWaitedForThenPutBack(): void
    {
        // the server stops the updater, not the health check it started: that check may still change the database
        $storage = $this->root . '/storage';
        $this->journal()->begin(['kind' => 'update', 'owner' => 'plugin:ledger', 'from' => '1.0.0', 'to' => '1.1.0', 'backup' => 'x', 'target' => $this->root . '/content/plugins/ledger'], UpdateJournal::SWITCHING);
        $updater = proc_open([PHP_BINARY, __DIR__ . '/fixtures/check-outlives-updater.php', $storage, '2'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        do {
            usleep(20_000);
            $status = proc_get_status($updater);
        } while ($status['running']);
        proc_close($updater);
        $this->assertSame(9, $status['termsig'], 'the updater was killed');

        $this->assertTrue(Files::lockHeld($storage), 'the check inherited the lock');
        $this->assertNull($this->journal()->pending(), 'nothing is put back while the check runs');
        $this->assertNotNull($this->journal()->runningLong(time() + UpdateJournal::LONG + 1), 'after a while, it shows as unusually long');
        $this->assertNull($this->journal()->runningLong(), 'not right away');

        $deadline = microtime(true) + 10;
        while (Files::lockHeld($storage) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $this->assertNotNull($this->journal()->pending(), 'once the check ends, the next request puts the update back');
    }

    public function testTheHealthCheckOfAnUpdateHasATimeLimitOfItsOwn(): void
    {
        $command = (new \Kestello\Cms\Package\Update\ProcessProbe($this->root, '/usr/bin/php'))->command('plugin:ledger');
        $this->assertSame(['/usr/bin/php', '-d', 'max_execution_time=120', $this->root . '/bin/cms', 'update:probe', 'plugin:ledger'], $command);
    }

    public function testVisitorsStayOutWhileAnUpdateHasNotFinishedHoweverLongItTakes(): void
    {
        $storage = $this->root . '/storage';
        $maintenance = new Maintenance($storage);
        Kernel::holdDuringUpdate($this->journal(), $maintenance);
        $this->assertNull($maintenance->current(), 'no update, no maintenance');

        $bypass = $maintenance->on(Maintenance::VISITORS, 'update ledger', 30);
        $file = $storage . '/framework/maintenance.json';
        $data = json_decode((string) file_get_contents($file), true);
        file_put_contents($file, json_encode(['expires_at' => time() - 60] + $data));
        $this->assertNull($maintenance->current(), 'the update\'s 30 minutes ran out');

        $this->journal()->begin(['kind' => 'update', 'owner' => 'plugin:ledger', 'from' => '1.0.0', 'to' => '1.1.0', 'backup' => 'x', 'target' => $this->root . '/content/plugins/ledger'], UpdateJournal::SWITCHING);
        Kernel::holdDuringUpdate($this->journal(), $maintenance);
        $this->assertNotNull($maintenance->current(), 'still updating: visitors still get the maintenance page');
        $this->assertFalse($maintenance->blocks('/', false, $bypass), 'the update\'s own check still gets through');
        $this->assertTrue($maintenance->blocks('/', false));

        $maintenance->off();
        Kernel::holdDuringUpdate($this->journal(), $maintenance);
        $this->assertSame(Maintenance::VISITORS, $maintenance->current()['mode'] ?? null, 'turned back on when someone removed it');
    }

    public function testTheUpdatesScreenSaysWhenAnUpdateRunsUnusuallyLong(): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $this->journal()->begin(['kind' => 'update', 'owner' => 'core', 'from' => '0.1.0', 'to' => '0.2.0', 'backup' => 'x', 'target' => $this->root . '/core/releases/0.2.0'], UpdateJournal::SWITCHING);
        $file = $this->root . '/storage/framework/update.json';
        file_put_contents($file, json_encode(['started_at' => time() - UpdateJournal::LONG - 60] + json_decode((string) file_get_contents($file), true)));
        $this->core('0.1.0');
        (new \Kestello\Cms\Health\HealthModule())->boot($this->cms);

        Files::locked($this->root . '/storage', function (): void {
            $stopped = json_decode($this->api('GET', '/updates')->content(), true)['data']['stopped_update'];
            $this->assertSame([true, false, 'Kestello'], [$stopped['running'], $stopped['gave_up'], $stopped['name']], json_encode($stopped));
            $check = $this->cms->health->run(fresh: true, only: ['core.update-stopped'])['checks'][0];
            $this->assertSame('recommended', $check['status'], json_encode($check));
            $this->assertStringContainsString('The update of Kestello from 0.1.0 to 0.2.0 has been running for 16 minutes.', $check['message'], 'its age, not a clock time in the server\'s time zone');
            $this->assertSame(409, $this->api('POST', '/updates/recover')->statusCode(), 'nothing to try again while it runs');
        });
        $this->assertFalse(json_decode($this->api('GET', '/updates')->content(), true)['data']['stopped_update']['running'], 'once it lets go, it is a stopped update');
    }

    public function testTheVisitorIsSentBackToAPageOnThisSiteOnly(): void
    {
        $this->assertSame('/blog/post?page=2', Kernel::sameSiteLocation('/blog/post', 'page=2'));
        $this->assertSame('/', Kernel::sameSiteLocation('//evil.test/x'));
        $this->assertSame('/', Kernel::sameSiteLocation('/\\evil.test'));
        $this->assertSame('/', Kernel::sameSiteLocation("/x\r\nSet-Cookie: a=b"));
        $this->assertSame('/', Kernel::sameSiteLocation('https://evil.test/'));
        $this->assertSame('/a?b=c', Kernel::sameSiteLocation('/a', "b=c\r\n"));
    }
}
