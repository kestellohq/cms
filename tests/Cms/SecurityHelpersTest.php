<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Extension\AssetController;
use Kestello\Cms\Extension\PackageChecker;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\Recovery;
use Kestello\Framework\Testing\TestCase;

final class SecurityHelpersTest extends TestCase
{
    private const string SECRET = '0123456789abcdef0123456789abcdef';

    public function testRecoveryTokensVerifyAndExpire(): void
    {
        $recovery = new Recovery(self::SECRET);
        $token = $recovery->createToken(600, now: 1_000_000_000);

        $this->assertTrue($recovery->verify($token, now: 1_000_000_100));
        $this->assertFalse($recovery->verify($token, now: 1_000_000_601), 'expired');
        $this->assertFalse((new Recovery(str_repeat('x', 32)))->verify($token, now: 1_000_000_100), 'other key');

        [$expires, $mac] = explode('.', $token);
        $this->assertFalse($recovery->verify(($expires + 99999) . '.' . $mac, now: 1_000_000_100), 'tampered expiry');
        $this->assertFalse($recovery->verify('garbage'));
    }

    public function testRecoveryRequiresAStrongSecret(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn() => new Recovery('short'));
    }

    public function testAssetResolutionIsConfinedToTheAssetsDirectory(): void
    {
        $root = $this->tempDir();
        mkdir("$root/assets/css", 0777, true);
        file_put_contents("$root/assets/css/app.css", 'body{}');
        file_put_contents("$root/assets/shell.php", '<?php');
        file_put_contents("$root/secret.css", 'x');
        symlink("$root/secret.css", "$root/assets/link.css");

        $assets = "$root/assets";
        $this->assertNotNull(AssetController::resolve($assets, 'css/app.css'));
        $this->assertNull(AssetController::resolve($assets, '../secret.css'), 'traversal');
        $this->assertNull(AssetController::resolve($assets, 'shell.php'), 'php never served');
        $this->assertNull(AssetController::resolve($assets, 'link.css'), 'symlink escaping root');
        $this->assertNull(AssetController::resolve($assets, "css/app.css\0.png"), 'null byte');
        $this->assertNull(AssetController::resolve($assets, '/etc/passwd'));

        // the server-only SSR bundle, however it is named
        mkdir("$assets/build/ssr", 0777, true);
        file_put_contents("$assets/build/ssr/app.js", 'export const secret = 1;');
        symlink("$assets/build/ssr/app.js", "$assets/build/public.js");
        foreach (['build/ssr/app.js', 'Build/SSR/app.js', 'BUILD/ssr/app.js', 'build/public.js'] as $path) {
            $this->assertNull(AssetController::resolve($assets, $path), $path);
        }
        $this->assertNull(AssetController::resolve($assets, 'css/.Hidden.css'));
    }

    public function testPackageCheckerFlagsDangerousCode(): void
    {
        $dir = $this->tempDir() . '/shady';
        mkdir("$dir/src", 0777, true);
        mkdir("$dir/assets", 0777, true);
        file_put_contents("$dir/plugin.json", json_encode([
            'slug' => 'shady', 'name' => 'Shady', 'version' => '1.0.0',
            'requires' => ['core' => '*'], 'namespace' => 'Shady\\',
        ]));
        file_put_contents("$dir/src/Bad.php", <<<'PHP'
        <?php
        namespace Other\Place;
        class Bad {
            public function run($cmd) {
                shell_exec($cmd);
                $x = unserialize($_POST['data']);
                eval('return 1;');
                `ls`;
                $this->exec('fine: method call');
                include $cmd;
            }
        }
        PHP);
        file_put_contents("$dir/assets/x.php", '<?php');
        mkdir("$dir/vendor/lib", 0777, true);
        file_put_contents("$dir/vendor/lib/Run.php", '<?php passthru($_GET["c"]);');
        file_put_contents("$dir/src/helpers.txt", '<?php proc_open("sh", [], $p);');
        file_put_contents("$dir/src/tool.phar", 'x');
        file_put_contents("$dir/assets/widget.js", "el.innerHTML = 1;\nconst f = new Function('a', 'return a');\nexport default {\n  template: '<b>{{ x }}</b>',\n};");

        $messages = array_map(fn(array $f) => $f['level'] . ' ' . $f['message'], (new PackageChecker())->check($dir, PackageType::Plugin));
        $all = implode("\n", $messages);

        foreach (['shell_exec()', 'unserialize()', '$_POST', 'eval()', 'backtick', 'dynamic include', 'outside the declared', 'PHP files are not allowed in assets/', 'dynamic code execution in JavaScript', 'string templates need the Vue compiler'] as $expected) {
            $this->assertContains($expected, $all);
        }
        $plainExec = array_filter($messages, fn(string $m) => str_contains($m, ' exec(): '));
        $this->assertCount(0, $plainExec, 'method calls named exec are fine');
        $this->assertContains('requires.core is "*"', $all);
        $this->assertContains('vendor/lib/Run.php', implode("\n", array_column((new PackageChecker())->check($dir, PackageType::Plugin), 'file')), 'vendor/ is checked');
        $this->assertContains('proc_open()', $all, 'PHP in a file of any name is checked');
        $this->assertContains('.phar', $all);
    }

    public function testCustomCssCannotReachOtherSites(): void
    {
        $check = [\Kestello\Cms\Design\ThemeStyles::class, 'checkCss'];
        $this->assertNull($check('.hero { background: url(/files/a.png); background-image: image-set("/files/a.png" 1x, url(/files/a@2x.png) 2x); }'));
        $this->assertNotNull($check('.hero { background: url(https://tracker.test/p.png); }'));
        $this->assertNotNull($check('.hero { background-image: image-set("https://tracker.test/p.png" 1x); }'), 'image-set takes plain strings');
        $this->assertNotNull($check('.hero { background-image: -webkit-image-set(\'//tracker.test/p.png\' 1x); }'));
        $this->assertNotNull($check('.hero { background-image: src("https://tracker.test/p.png"); }'));
    }
}
