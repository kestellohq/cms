<?php

declare(strict_types=1);

namespace Kestello\Tests\System;

use Kestello\Cms\System\CachePruner;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Cache\FileStore;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Testing\TestCase;

/** Expired cache entries (one per visitor per minute for rate limits) are removed on a schedule, not kept forever. */
final class CachePrunerTest extends TestCase
{
    public function testExpiredEntriesAreRemovedFromStoresThatKeepThem(): void
    {
        $dir = sys_get_temp_dir() . '/kestello-prune-' . bin2hex(random_bytes(4));
        $files = new FileStore($dir);
        $limiter = new RateLimiter($files);
        foreach (range(1, 5) as $n) {
            $limiter->hit("search|ip|192.0.2.{$n}", 10, 1);      // a visitor's bucket, for one second
        }
        $files->put('kept', 'v');
        sleep(2);

        $removed = CachePruner::prune([$files, new ArrayStore()]);      // an array store has nothing to prune: skipped

        $this->assertTrue($removed >= 5, "expired buckets removed ({$removed})");
        $this->assertSame('v', $files->get('kept'), 'entries without an expiry stay');
        $left = iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)));
        $this->assertSame(1, $left);
        $files->flush();
    }
}
