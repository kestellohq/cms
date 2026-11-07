<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue;

use Kestello\Contracts\Queue\Job;

/**
 * A batch being put together:
 *
 *   $id = $cms->queue->batch($jobs)
 *       ->name('Regenerate thumbnails')
 *       ->then(new NotifyDone($userId))      // every job succeeded
 *       ->catch(new AlertAdmins('thumbs'))   // the first job failed for good
 *       ->finally(new CleanupTemp($dir))     // the batch is over, either way
 *       ->dispatch();
 *
 * Callbacks are jobs (JSON payloads, like everything on the queue), not
 * closures. A failure cancels the rest of the batch unless allowFailures().
 *
 * @api
 */
final class PendingBatch
{
    private string $name = '';
    private ?Job $then = null;
    private ?Job $catch = null;
    private ?Job $finally = null;
    private bool $allowFailures = false;
    private ?string $queue = null;

    /** @param list<Job> $jobs */
    public function __construct(private readonly JobQueue $manager, private readonly array $jobs) {}

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    public function then(Job $job): self
    {
        $this->then = $job;
        return $this;
    }
    public function catch(Job $job): self
    {
        $this->catch = $job;
        return $this;
    }
    public function finally(Job $job): self
    {
        $this->finally = $job;
        return $this;
    }
    public function allowFailures(bool $allow = true): self
    {
        $this->allowFailures = $allow;
        return $this;
    }
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /** Store the batch and push its jobs. Returns the batch id. */
    public function dispatch(): string
    {
        return $this->manager->dispatchBatch($this->jobs, $this->name, $this->queue, $this->allowFailures, $this->then, $this->catch, $this->finally);
    }
}
