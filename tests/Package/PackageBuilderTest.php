<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\PackageBuilder;
use Kestello\Framework\Testing\TestCase;
use ZipArchive;

/** What goes into a package zip, and what stays in the developer's folder. */
final class PackageBuilderTest extends TestCase
{
    public function testOnlyWhatASiteRunsGoesIn(): void
    {
        foreach (['plugin.json', 'src/Provider.php', 'assets/build/.vite/manifest.json', 'resources/js/pages/admin/Page.vue', 'CHANGELOG.md', 'src/tests/Helper.php', 'src/dist/map.php'] as $path) {
            $this->assertTrue(PackageBuilder::ships($path), "{$path} ships");
        }
        foreach (['.env', '.git/config', 'src/.DS_Store', 'node_modules/vue/index.js', 'resources/node_modules/x.js', 'tests', 'tests/ProviderTest.php', 'dist', 'dist/acme-1.0.0.zip'] as $path) {
            $this->assertFalse(PackageBuilder::ships($path), "{$path} stays out");
        }
    }

    public function testABuildInsideThePackageFolderLeavesTestsAndEarlierBuildsOut(): void
    {
        $dir = $this->tempDir() . '/acme';
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/tests', 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode(['slug' => 'acme', 'name' => 'Acme', 'version' => '1.0.0', 'requires' => ['core' => '^0.1']]));
        file_put_contents($dir . '/src/notes.php', "<?php\n");
        file_put_contents($dir . '/tests/AcmeTest.php', "<?php\n");

        PackageBuilder::build($dir, $dir . '/dist');
        $zip = PackageBuilder::build($dir, $dir . '/dist')['zip'];   // the second build sees the first one's dist/

        $archive = new ZipArchive();
        $this->assertTrue($archive->open($zip) === true);
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = (string) $archive->getNameIndex($i);
        }
        $archive->close();
        sort($names);
        $this->assertSame(['acme/plugin.json', 'acme/src/notes.php'], array_values(array_filter($names, static fn(string $n): bool => !str_ends_with($n, '/'))));
    }
}
