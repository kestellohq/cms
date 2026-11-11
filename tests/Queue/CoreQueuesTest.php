<?php

declare(strict_types=1);

namespace Kestello\Tests\Queue;

use Kestello\Cms\Queue\JobQueue;
use Kestello\Framework\Testing\TestCase;

/** Core only queues work where the default workers look for it. */
final class CoreQueuesTest extends TestCase
{
    public function testEveryQueueCoreNamesIsOneTheWorkersRead(): void
    {
        $root = dirname(__DIR__, 2) . '/core/src';
        $named = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // queue: 'x' in push(), chain() and #[Queued]; docblock examples count too, so they stay true
            preg_match_all("/\\bqueue:\\s*'([a-z0-9_-]+)'/", (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $queue) {
                $named[$queue][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertNotSame([], $named, 'the scan finds the queues core names');
        foreach ($named as $queue => $files) {
            $this->assertContains($queue, JobQueue::QUEUES, "\"{$queue}\" is read by no default worker (cron's queue.work, queue:work), so jobs on it would wait for ever: " . implode(', ', array_unique($files)));
        }
    }
}
