<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\Registry\RootKeys;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Testing\TestCase;

/** The registry's root keys ship with Kestello (core/config/registry.php). */
final class RegistryRootKeysTest extends TestCase
{
    private static function core(): string
    {
        return dirname(__DIR__, 2) . '/core/config';
    }

    public function testKestelloShipsTheRegistrysTwoRootKeys(): void
    {
        $config = new Config(self::core());
        $this->assertTrue($config->get('registry.enabled'));
        $this->assertSame('https://registry.kestello.com/v1/', $config->get('registry.url'));
        // A signs, B is the spare: the ids keys/roots.json in kestellohq/registry names
        $keys = RootKeys::fromConfig($config->get('registry.root_keys'));
        $this->assertSame(['f8a1ed747b283d96', '699f19b70e49354a'], array_keys($keys));
        $this->assertSame([32, 32], array_map('strlen', array_values($keys)));
    }

    public function testASiteMayPointAtAMirrorOrTurnTheRegistryOff(): void
    {
        $site = $this->tempDir();
        file_put_contents("{$site}/registry.php", "<?php return ['enabled' => false, 'url' => 'https://mirror.example/kestello/v1/'];");
        $config = new Config([self::core(), $site]);
        $this->assertFalse($config->get('registry.enabled'));
        $this->assertSame('https://mirror.example/kestello/v1/', $config->get('registry.url'));
        $this->assertCount(2, RootKeys::fromConfig($config->get('registry.root_keys')), 'a mirror keeps the same keys');
    }

    public function testAnEntryThatIsntAKeyIsSkipped(): void
    {
        $this->assertSame([], RootKeys::fromConfig([['name' => 'Bad', 'public_key' => 'not base64!'], 'nonsense']));
        $this->assertSame([], RootKeys::fromConfig(null));
    }
}
