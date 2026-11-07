<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\Config;
use Kestello\Framework\Testing\TestCase;

final class ConfigTest extends TestCase
{
    public function testSiteConfigOverridesCoreDefaultsKeyByKey(): void
    {
        $core = $this->tempDir();
        $site = $this->tempDir();
        file_put_contents("$core/app.php", "<?php return ['name' => 'Kestello', 'debug' => false, 'mail' => ['port' => 25, 'host' => 'localhost']];");
        file_put_contents("$site/app.php", "<?php return ['name' => 'My Site', 'mail' => ['port' => 587]];");

        $config = new Config([$core, $site]);

        $this->assertSame('My Site', $config->get('app.name'));
        $this->assertFalse($config->get('app.debug'));
        $this->assertSame(587, $config->get('app.mail.port'));
        $this->assertSame('localhost', $config->get('app.mail.host'));
    }

    public function testMissingKeysReturnDefault(): void
    {
        $config = new Config($this->tempDir());

        $this->assertSame('fallback', $config->get('nope.key', 'fallback'));
        $this->assertFalse($config->has('nope.key'));
    }

    public function testSingleDirectoryStillSupported(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/db.php", "<?php return ['host' => 'db'];");

        $this->assertSame('db', (new Config($dir))->get('db.host'));
    }
}
