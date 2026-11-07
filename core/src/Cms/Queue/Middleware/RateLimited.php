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
 * At most $max runs per $perSeconds for a key, across every worker (the
 * count lives in the cache, so use a shared cache driver with several
 * servers). Jobs over the limit wait for the next window; that does not
 * use up an attempt.
 *
 *   new RateLimited('mailgun', max: 10, perSeconds: 60)
 *
 * @api
 */
final class RateLimited implements JobMiddleware
{
    public function __construct(
        private readonly string $key,
        private readonly int $max,
        private readonly int $perSeconds = 60,
        private readonly ?Store $cache = null,
    ) {
        if ($max < 1 || $perSeconds < 1) {
            throw new \InvalidArgumentException('RateLimited needs max >= 1 and perSeconds >= 1.');
        }
    }

    public function handle(Job $job, Closure $next): void
    {
        $cache = $this->cache ?? Cms::instance()?->drivers->resolve('cache');
        if (!$cache instanceof Store) {
            $next($job);
            return;
        }
        $now = time();
        $window = intdiv($now, $this->perSeconds);
        $bucket = 'job-rate:' . $this->key . ':' . $window;
        $cache->add($bucket, 0, $this->perSeconds + 5);
        if ($cache->increment($bucket) > $this->max) {
            $wait = ($window + 1) * $this->perSeconds - $now;
            throw new ReleaseJob(max(1, $wait) + random_int(0, min(5, $this->perSeconds)), "Rate limit {$this->key}: {$this->max} per {$this->perSeconds}s.");
        }
        $next($job);
    }
}
