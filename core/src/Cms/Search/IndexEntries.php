<?php

declare(strict_types=1);

namespace Kestello\Cms\Search;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;

/** Update entries' search documents on an external engine (queued, so saving never waits on it). */
final class IndexEntries implements Job
{
    /** @param list<int> $ids */
    public function __construct(public readonly array $ids) {}

    public function handle(): void
    {
        (Cms::instance() ?? throw new \RuntimeException('Not booted.'))->search->syncNow($this->ids);
    }

    public function retries(): int
    {
        return 5;
    }
    public function timeout(): int
    {
        return 120;
    }
    public function uniqueKey(): ?string
    {
        return count($this->ids) === 1 ? 'search-entry:' . $this->ids[0] : null;
    }
}
