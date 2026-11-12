<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Cache\FileStore;
use Kestello\Framework\Console\Commands\CacheClearCommand;
use Kestello\Framework\Console\Output;
use Kestello\Framework\Testing\TestCase;

/** cache:clear empties the cache store and the compiled templates, subfolders included. */
final class CacheClearCommandTest extends TestCase
{
    public function testItEmptiesTheStoreAndTheCompiledTemplates(): void
    {
        $dir = $this->tempDir();
        $store = new FileStore($dir . '/cache/data');
        $store->put('menus', ['a' => 1], 60);
        $store->put('settings', 'x', 60);
        @mkdir($dir . '/compiled/views/ab', 0777, true);
        file_put_contents($dir . '/compiled/.gitkeep', '');
        file_put_contents($dir . '/compiled/views/ab/page.php', '<?php');
        file_put_contents($dir . '/compiled/top.php', '<?php');

        $out = fopen('php://memory', 'w+');
        $command = new CacheClearCommand(static fn() => $store, $dir . '/compiled');
        $command->setOutput(new Output($out, false));
        $this->assertSame(0, $command->handle([], []));

        $this->assertNull($store->get('menus'), 'entries in subfolders are gone');
        $this->assertNull($store->get('settings'));
        $this->assertFileDoesNotExist($dir . '/compiled/views/ab/page.php');
        $this->assertFileDoesNotExist($dir . '/compiled/top.php');
        $this->assertFileExists($dir . '/compiled/.gitkeep');
        rewind($out);
        $this->assertStringContainsString('Compiled templates: 2 file(s) cleared', (string) stream_get_contents($out));
    }

    public function testAMissingCompiledFolderIsNothingToClear(): void
    {
        $this->assertSame(0, CacheClearCommand::emptyFolder($this->tempDir() . '/nowhere'));
    }
}
