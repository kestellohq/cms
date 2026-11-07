<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;

/** One slice of a running import, for sites with a queue worker; it queues the next one itself. */
final class RunImport implements Job
{
    /** @param float $seconds how long one slice works (a queue worker's job time limit is far longer) */
    public function __construct(public readonly int $importId, public readonly float $seconds = 45.0) {}

    /**
     * One slice, then the next job: each run queues exactly one more while
     * the import is running, so the chain never grows. When another step has
     * the slice (the admin screen, the CLI), it looks again a little later.
     */
    public function handle(): void
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $imports = $cms->container->make(WordPressImports::class);
        $state = $imports->step($this->importId, $this->seconds);
        if (($state['status'] ?? '') === 'running') {
            $imports->queueNext($this->importId, delay: ($state['busy'] ?? false) ? 30 : 0);
        }
    }

    public function retries(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 600;
    }
}
