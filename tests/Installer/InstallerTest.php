<?php

declare(strict_types=1);

namespace Kestello\Tests\Installer;

use Kestello\Cms\Installer\InstallerController;
use Kestello\Cms\Installer\InstallerRoutes;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Testing\TestCase;
use Kestello\Kernel;

final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kst-install-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0755, true);
        mkdir($this->root . '/storage/cache', 0755, true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function post(array $body): string
    {
        $controller = new InstallerController(new Paths($this->root, dirname(__DIR__, 2) . '/core'));
        $response = $controller->process(new Request('POST', '/install', '/install', [], [], $body, [], [], []));

        return (string) ($response->headers()['Location'] ?? '');
    }

    public function testConfigValuesSurviveExportUnchanged(): void
    {
        $value = ['name' => 'Studio (Belgrade), Ltd', 'q' => "it's \"quoted\" ]),\n", 'n' => [1, 2.5, true, null, []], 'nested' => ['a)' => ['b' => '),']]];
        $code = InstallerController::export($value);
        $this->assertSame($value, eval('return ' . $code . ';'));
        $this->assertFalse(str_contains($code, 'array ('), 'short arrays');
    }

    public function testTheAdminPasswordFollowsThePolicyAndIsNeverKeptInPlainText(): void
    {
        $base = ['step' => '4', 'admin_username' => 'ana', 'admin_email' => 'ana@example.test'];
        $this->assertTrue(str_contains($this->post($base + ['admin_password' => 'short1', 'admin_password_confirm' => 'short1']), 'step=4&error='));
        $this->assertTrue(str_contains($this->post($base + ['admin_password' => 'password1234', 'admin_password_confirm' => 'password1234']), 'step=4&error='), 'common password');

        $good = 'a-long-and-unusual-passphrase';
        $this->assertSame('/install?step=5', $this->post($base + ['admin_password' => $good, 'admin_password_confirm' => $good]));
        $this->assertFalse(isset($_SESSION['installer']['admin_password']));
        $this->assertFalse(str_contains(serialize($_SESSION), $good), 'nowhere in the session');
        $this->assertTrue(\Kestello\Support\Hash::verify($good, $_SESSION['installer']['admin_password_hash']));
    }

    public function testTheSiteAddressMustBeAWebAddress(): void
    {
        $this->assertTrue(str_contains($this->post(['step' => '3', 'site_name' => 'S', 'site_url' => 'javascript:alert(1)']), 'step=3&error='));
        $this->assertSame('/install?step=4', $this->post(['step' => '3', 'site_name' => 'S', 'site_url' => 'https://example.test/']));
        $this->assertSame('https://example.test', $_SESSION['installer']['site_url']);
    }

    public function testBeforeInstallationOnlyTheInstallerAndItsAssetsAreServed(): void
    {
        $before = [
            '/install' => null,
            '/install/complete' => null,
            '/assets/core/~0.1.0/build/app/index.js' => null,
            '/assets/core/vendor/vue.js' => null,
            '/' => '/install',
            '/admin' => '/install',
            '/installer' => '/install',
            '/assets/themes/base/theme.css' => '/install',
            '/assets/plugins/x/app.js' => '/install',
        ];
        foreach ($before as $uri => $expected) {
            $this->assertSame($expected, Kernel::installerRedirect($uri, false), $uri);
        }

        $this->assertSame('/', Kernel::installerRedirect('/install', true));
        $this->assertNull(Kernel::installerRedirect('/install/complete', true));
    }

    public function testTheInstallerServesItsOwnScriptsAndNothingPrivate(): void
    {
        $core = dirname(__DIR__, 2) . '/core';
        $router = new Router();
        InstallerRoutes::assets($router, new Paths($this->root, $core));
        $get = static fn(string $uri): \Kestello\Framework\Core\Response => $router->dispatch(new Request('GET', $uri, $uri, [], [], [], [], [], []));

        $files = glob($core . '/resources/assets/build/app/index-*.js') ?: [];
        $this->assertNotSame([], $files, 'the built admin entry exists');
        $script = $get('/assets/core/~0.1.0/build/app/' . basename($files[0]));
        $this->assertSame(200, $script->statusCode());
        $this->assertStringStartsWith('text/javascript', $script->headers()['Content-Type'] ?? '');
        $this->assertSame(file_get_contents($files[0]), $script->content(), 'byte for byte, so integrity checks pass');

        foreach (['/assets/core/build/.vite/manifest.json', '/assets/core/../../config/app.php', '/assets/core/build/ssr/app.mjs'] as $private) {
            $this->assertSame(404, $get($private)->statusCode(), $private);
        }
    }

    public function testMissingFoldersAreCreatedForAnUploadThatDroppedThem(): void
    {
        // Some hosts' unzip drops empty folders and ones holding only .gitkeep.
        $root = $this->root . '/uploaded';
        mkdir($root);
        $paths = new Paths($root, dirname(__DIR__, 2) . '/core');

        $folders = InstallerController::prepareFolders($paths);

        $this->assertSame(['config', 'storage', 'storage/cache', 'storage/compiled', 'storage/logs', 'storage/sessions', 'storage/app', 'content/plugins'], array_keys($folders));
        foreach ($folders as $label => $dir) {
            $this->assertTrue(is_dir($dir) && is_writable($dir), $label);
        }
        $this->assertSame($folders, InstallerController::prepareFolders($paths), 'a second run changes nothing');
    }

    public function testARefusedAdministratorKeepsTheNameButNotAnOlderPassword(): void
    {
        $good = 'a-long-and-unusual-passphrase';
        $base = ['step' => '4', 'admin_username' => 'ana', 'admin_email' => 'ana@example.test'];
        $this->assertSame('/install?step=5', $this->post($base + ['admin_password' => $good, 'admin_password_confirm' => $good]));

        $this->assertTrue(str_contains($this->post(['step' => '4', 'admin_username' => 'bo', 'admin_email' => 'bo@example.test', 'admin_password' => 'x', 'admin_password_confirm' => 'x']), 'step=4&error='));

        $this->assertSame(['bo', 'bo@example.test'], [$_SESSION['installer']['admin_username'], $_SESSION['installer']['admin_email']], 'typed again only the password');
        $this->assertArrayNotHasKey('admin_password_hash', $_SESSION['installer'], 'ana\'s password must not install bo');
        $this->assertSame(4, InstallerController::firstIncompleteStep($_SESSION['installer'] + ['db_driver' => 'sqlite', 'site_name' => 'S']));
    }

    public function testAPublicDemoSkipsTheAdministratorAndIsWrittenToTheConfig(): void
    {
        $_SESSION['installer'] = ['db_driver' => 'sqlite', 'db_name' => 'site'];
        mkdir($this->root . '/storage/database', 0755, true);
        $this->assertSame('/install?step=5', $this->post(['step' => '3', 'site_name' => 'Demo', 'site_url' => 'https://demo.example.test', 'site_kind' => 'demo']), 'no administrator step');
        $this->assertSame('demo', $_SESSION['installer']['admin_username']);
        $this->assertTrue(\Kestello\Support\Hash::verify('demo', $_SESSION['installer']['admin_password_hash']));

        $this->assertSame('/install?step=4', $this->post(['step' => '3', 'site_name' => 'Demo', 'site_url' => 'https://demo.example.test', 'site_kind' => 'site']));
        $this->assertSame(4, InstallerController::firstIncompleteStep($_SESSION['installer']), 'a website gets its own administrator, not the demo account');

        $this->post(['step' => '3', 'site_name' => 'Demo', 'site_url' => 'https://demo.example.test', 'site_kind' => 'demo']);
        $this->assertSame('/install/complete', $this->post(['step' => '5']));
        $config = require $this->root . '/config/app.php';
        $this->assertTrue($config['demo'] ?? false);
        $user = (new \Kestello\Framework\Database\Connection(['driver' => 'sqlite', 'database' => $this->root . '/storage/database/site.sqlite']))->table('users')->first();
        $this->assertSame(['demo', 'admin'], [$user['username'] ?? null, $user['role'] ?? null]);
    }

    public function testAWebsiteIsNotADemo(): void
    {
        $_SESSION['installer'] = ['db_driver' => 'sqlite', 'db_name' => 'site'];
        mkdir($this->root . '/storage/database', 0755, true);
        $this->post(['step' => '3', 'site_name' => 'Mine', 'site_url' => 'https://example.test']);
        $good = 'a-long-and-unusual-passphrase';
        $this->post(['step' => '4', 'admin_username' => 'ana', 'admin_email' => 'ana@example.test', 'admin_password' => $good, 'admin_password_confirm' => $good]);
        $this->assertSame('/install/complete', $this->post(['step' => '5']));
        $this->assertFalse(array_key_exists('demo', require $this->root . '/config/app.php'));
    }

    public function testLaterStepsSendPeopleBackToTheFirstUnfinishedOne(): void
    {
        $this->assertSame(2, InstallerController::firstIncompleteStep([]));
        $this->assertSame(3, InstallerController::firstIncompleteStep(['db_driver' => 'sqlite']));
        $this->assertSame(4, InstallerController::firstIncompleteStep(['db_driver' => 'sqlite', 'site_name' => 'S']));
        $this->assertNull(InstallerController::firstIncompleteStep(['db_driver' => 'sqlite', 'site_name' => 'S', 'admin_password_hash' => 'h']));

        $_SESSION = [];
        $controller = new InstallerController(new Paths($this->root, dirname(__DIR__, 2) . '/core'));
        $response = $controller->process(new Request('POST', '/install', '/install', [], [], ['step' => '5'], [], [], []));
        $this->assertSame('/install?step=2', $response->headers()['Location'] ?? null, 'nothing is installed from an empty session');
        $this->assertFalse(is_file($this->root . '/config/app.php'));
    }

    public function testEveryFailedCheckSaysWhatToDo(): void
    {
        $checks = InstallerController::requirementChecks(new Paths($this->root, dirname(__DIR__, 2) . '/core'));
        $byName = array_column($checks, null, 'name');

        $this->assertSame([false, 'Missing'], [$byName['storage/logs']['passed'], $byName['storage/logs']['current']], 'not created yet in this test');
        $this->assertStringStartsWith('Create this folder', $byName['storage/logs']['fix']);
        $this->assertSame([true, 'Writable'], [$byName['config']['passed'], $byName['config']['current']]);
        foreach ($checks as $check) {
            $this->assertNotSame('', $check['fix'], $check['name']);
            $this->assertContains($check['group'], ['PHP', 'Folders']);
        }
    }

    public function testTheInstallerChecksTheFolderUploadsGoTo(): void
    {
        $paths = new Paths($this->root, dirname(__DIR__, 2) . '/core');
        $folders = InstallerController::writableFolders($paths);

        // the media library's local disk is storage/app/media (StorageManager, rooted at storage/app)
        $this->assertSame($paths->storage('app'), $folders['storage/app'] ?? null);
        $this->assertArrayNotHasKey('content/uploads', $folders, 'nothing is uploaded there');
    }

    public function testUploadsFollowAnotherStorageFolder(): void
    {
        $folders = InstallerController::writableFolders(new Paths($this->root, dirname(__DIR__, 2) . '/core', '/srv/kestello-data'));

        $this->assertSame('/srv/kestello-data/app', $folders['storage/app']);
    }
}
