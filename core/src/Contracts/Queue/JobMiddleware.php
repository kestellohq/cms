<?php

declare(strict_types=1);

namespace Kestello\Contracts\Queue;

use Closure;

/**
 * Wraps a job's handle(). A job lists its middleware in middleware():
 *
 *   public function middleware(): array
 *   {
 *       return [new RateLimited('mailgun', max: 10, perSeconds: 60), new WithoutOverlapping("import:{$this->fileId}")];
 *   }
 *
 * Call $next($job) to continue; throw Kestello\Cms\Queue\ReleaseJob to put
 * the job back for later without using up an attempt; throw anything else
 * to fail the attempt.
 *
 * @api
 */
interface JobMiddleware
{
    /** @param Closure(Job): void $next */
    public function handle(Job $job, Closure $next): void;
}
