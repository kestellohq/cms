<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue\Middleware;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Queue\ReleaseJob;
use Kestello\Contracts\Cache\Store;
use Kestello\Contracts\Queue\Job;
use Kestello\Contracts\Queue\JobMiddleware;

/**
 * Only one job with this key runs at a time; the others wait $releaseAfter
 * seconds and try again (without using up an attempt). The lock expires
 * after $expiresAfter seconds in case a worker dies holding it.
 *
 *   new WithoutOverlapping("import:{$this->fileId}")
 *
 * @api
 */
final class WithoutOverlapping implements JobMiddleware
{
    public function __construct(
        private readonly string $key,
        private readonly int $releaseAfter = 10,
        private readonly int $expiresAfter = 300,
        private readonly ?Store $cache = null,
    ) {}

    public function handle(Job $job, Closure $next): void
    {
        $cache = $this->cache ?? Cms::instance()?->drivers->resolve('cache');
        if (!$cache instanceof Store) {
            $next($job);
            return;
        }
        $lock = $cache->lock('job-overlap:' . $this->key, max(1, $this->expiresAfter));
        if (!$lock->acquire()) {
            throw new ReleaseJob(max(1, $this->releaseAfter), "Another job holds {$this->key}.");
        }
        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
