<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\Paths;
use Kestello\Framework\Testing\TestCase;

final class PathsTest extends TestCase
{
    public function testSiteAndCorePathsAreSeparate(): void
    {
        $paths = new Paths('/site/', '/site/core/releases/1.2.0/');

        $this->assertSame('/site', $paths->root());
        $this->assertSame('/site/config/app.php', $paths->config('app.php'));
        $this->assertSame('/site/storage/compiled', $paths->compiled());
        $this->assertSame('/site/content/plugins', $paths->plugins());
        $this->assertSame('/site/core/releases/1.2.0/resources/assets', $paths->coreAssets());
        $this->assertSame('/site/core/releases/1.2.0/database/migrations', $paths->coreMigrations());
        $this->assertSame('/site/content/themes', $paths->themes());
    }

    public function testStorageCanLiveElsewhere(): void
    {
        $this->assertSame('/site/storage/cache', Paths::fromEnv('/site', '/core', [])->cache(), 'the default');
        $this->assertSame('/site/storage/cache', Paths::fromEnv('/site', '/core', ['KESTELLO_STORAGE' => ''])->cache(), 'empty is the default');
        $this->assertSame('/tmp/run-1/cache', Paths::fromEnv('/site', '/core', ['KESTELLO_STORAGE' => '/tmp/run-1/'])->cache());
        $this->assertSame('/site/var/smoke/logs', Paths::fromEnv('/site', '/core', ['KESTELLO_STORAGE' => './var/smoke'])->logs(), 'relative to the site');
        $this->assertSame('/site/../shared/app', Paths::fromEnv('/site', '/core', ['KESTELLO_STORAGE' => '../shared'])->storage('app'));
        $this->assertSame('/site/config', Paths::fromEnv('/site', '/core', ['KESTELLO_STORAGE' => '/tmp/x'])->config(), 'nothing else moves');
    }

    public function testJoinNormalisesLeadingSlash(): void
    {
        $paths = new Paths('/site', '/core');

        $this->assertSame('/site/storage/logs/app.log', $paths->storage('/logs/app.log'));
    }
}
