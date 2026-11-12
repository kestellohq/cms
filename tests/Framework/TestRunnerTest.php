<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;
use Kestello\Framework\Testing\TestRunner;

final class TestRunnerTest extends TestCase
{
    public function testItFindsTestFilesButNotThoseInHiddenFolders(): void
    {
        $root = sys_get_temp_dir() . '/kst-runner-' . bin2hex(random_bytes(4));
        $make = static function (string $path) use ($root): void {
            @mkdir(dirname("{$root}/{$path}"), 0o777, true);
            file_put_contents("{$root}/{$path}", "<?php\n");
        };
        try {
            foreach (['ATest.php', 'sub/BTest.php', 'sub/helper.php', 'sub/Fixture.php', 'browser/.site/kestello/core/CTest.php', '.cache/DTest.php'] as $file) {
                $make($file);
            }
            $found = array_map(static fn(string $f): string => substr($f, strlen($root) + 1), TestRunner::testFiles($root));
            // the browser tests install a whole Kestello, with its own tests, in tests/browser/.site
            $this->assertSame(['ATest.php', 'sub/BTest.php'], $found);
            $this->assertSame(["{$root}/sub/BTest.php"], TestRunner::testFiles("{$root}/sub/BTest.php"), 'one file');
            $this->assertSame([], TestRunner::testFiles("{$root}/sub/helper.php"));
            $this->assertSame([], TestRunner::testFiles("{$root}/missing"));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
