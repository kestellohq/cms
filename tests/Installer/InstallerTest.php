<?php

declare(strict_types=1);

namespace Kestello\Tests\Installer;

use Kestello\Cms\Installer\InstallerController;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Testing\TestCase;

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
}
