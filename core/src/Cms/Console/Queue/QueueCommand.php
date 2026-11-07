<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Cms\Cms;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Contracts\Cache\Store as CacheStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Console\Command;

/** @internal */
abstract class QueueCommand extends Command
{
    protected function queue(): JobQueue
    {
        return Cms::instance()?->queue ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function cache(): CacheStore
    {
        $c = Cms::instance()?->container;

        return $c !== null && $c->has(CacheStore::class) ? $c->make(CacheStore::class) : new ArrayStore();
    }
}
