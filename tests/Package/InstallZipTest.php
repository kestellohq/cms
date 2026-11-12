<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\Update\InstallZip;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipEntry;

/** The zip people download to install Kestello: what goes in, and what never does. */
final class InstallZipTest extends TestCase
{
    public function testTheZipHoldsAnUninstalledSiteAndNothingFromTheCheckout(): void
    {
        $root = dirname(__DIR__, 2);
        $built = InstallZip::build($root, $this->tempDir());
        $version = trim((string) file_get_contents($root . '/core/VERSION'));

        $this->assertSame("kestello-{$version}.zip", basename($built['zip']));
        $this->assertSame(hash_file('sha256', $built['zip']) . '  ' . basename($built['zip']) . "\n", file_get_contents($built['zip'] . '.sha256'));

        $names = array_map(static fn(ZipEntry $entry): string => $entry->name, Zip::open($built['zip'])->entries());
        $this->assertSame($built['files'], count($names));
        foreach ($names as $name) {
            $this->assertStringStartsWith('kestello/', $name, 'one folder to upload');
        }
        foreach (['.htaccess', 'public/.htaccess', 'public/index.php', 'bin/cms', 'core/VERSION', 'core/bootstrap.php', 'core/resources/assets/build/.vite/manifest.json', 'content/themes/base/theme.json', 'content/themes/base/screenshot.webp', 'content/themes/exhibit/theme.json', 'content/themes/exhibit/screenshot.webp', 'content/themes/exhibit/resources/lang/es.json', 'LICENSE'] as $needed) {
            $this->assertContains("kestello/{$needed}", $names, $needed);
        }

        $leaked = preg_grep('#^kestello/(tests|tools|docker|config|storage|content/plugins|\.github|\.agents)/|node_modules|/\.(?!htaccess$|vite/)|content/themes/(?!base/|exhibit/)|content/themes/base/tools/|(AGENTS|CLAUDE)\.md$|\.env|package(-lock)?\.json$|^kestello/core/(releases/|current\.php)#', $names);
        $this->assertSame([], array_values($leaked ?: []));
    }

    public function testWhatIsKeptInsideEachFolder(): void
    {
        $this->assertTrue(InstallZip::keep('public', '.htaccess'));
        $this->assertTrue(InstallZip::keep('core', 'resources/assets/build/.vite/manifest.json'));
        $this->assertFalse(InstallZip::keep('core', 'resources/js/.DS_Store'));
        $this->assertFalse(InstallZip::keep('core', 'node_modules/vue/index.js'));
        $this->assertFalse(InstallZip::keep('core', 'releases/0.1.0/VERSION'));
        $this->assertFalse(InstallZip::keep('core', 'current.php'));
        $this->assertTrue(InstallZip::keep('core', 'src/Cms/Releases.php'), 'only the top-level releases folder');
        $this->assertFalse(InstallZip::keep('content/themes/base', 'tools/make-enamel.py'));
        $this->assertTrue(InstallZip::keep('content/themes/base', 'assets/img/enamel.webp'));
    }

    public function testAFolderThatIsNotACheckoutIsRefused(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/core', 0775, true);
        file_put_contents($root . '/core/VERSION', '1.0.0');

        $error = $this->assertThrows(\RuntimeException::class, static fn() => InstallZip::build($root, $root . '/dist'));
        $this->assertStringContainsString('is missing', $error->getMessage());
    }
}
