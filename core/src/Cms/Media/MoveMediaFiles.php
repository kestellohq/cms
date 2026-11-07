<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;

/** One slice of a move between disks; queues the next slice itself (MediaStorage::step()). */
final class MoveMediaFiles implements Job
{
    public function handle(): void
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $cms->container->make(MediaStorage::class)->step(maxFiles: 50, seconds: 60.0);
    }

    public function retries(): int
    {
        return 3;
    }
    public function timeout(): int
    {
        return 300;
    }
    public function uniqueKey(): ?string
    {
        return 'media-move';
    }
}
