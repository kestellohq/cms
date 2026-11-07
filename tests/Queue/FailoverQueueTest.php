<?php

declare(strict_types=1);

namespace Kestello\Tests\Queue;

use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Queue\ReservedJob;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Framework\Queue\FailoverQueue;
use Kestello\Framework\Testing\TestCase;

final class FailoverQueueTest extends TestCase
{
    public function testJobsGoToTheSecondQueueWhenTheFirstIsDownAndAreFinishedWhereTheyCameFrom(): void
    {
        $down = new class implements Queue {
            public function push(string $queue, string $payload, int $delay = 0, ?string $uniqueKey = null): ?string
            {
                throw new \RuntimeException('Connection refused');
            }
            public function reserve(array $queues, int $visibility): ?ReservedJob
            {
                throw new \RuntimeException('Connection refused');
            }
            public function touch(ReservedJob $job, int $visibility): void
            {
                throw new \LogicException('not mine');
            }
            public function delete(ReservedJob $job): void
            {
                throw new \LogicException('not mine');
            }
            public function release(ReservedJob $job, int $delay = 0, bool $refund = false): void
            {
                throw new \LogicException('not mine');
            }
            public function stats(): array
            {
                throw new \RuntimeException('Connection refused');
            }
            public function clear(?string $queue = null): int
            {
                throw new \RuntimeException('Connection refused');
            }
        };
        $db = new ArrayQueue();
        $q = new FailoverQueue($down, $db);

        $this->assertNotNull($q->push('default', '{"job":1}'), 'the job is kept');
        $job = $q->reserve(['default'], 60);
        $this->assertSame('{"job":1}', $job?->payload, 'and a worker gets it');
        $q->delete($job);           // finished on the queue it came from, not the dead one
        $this->assertNull($q->reserve(['default'], 60));
    }
}
