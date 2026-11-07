<?php

declare(strict_types=1);

namespace Kestello\Cms\Search;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;

/** Rebuild every search collection (queued when the search engine changes). */
final class ReindexSearch implements Job
{
    public function handle(): void
    {
        (Cms::instance() ?? throw new \RuntimeException('Not booted.'))->search->reindex();
    }

    public function retries(): int
    {
        return 2;
    }
    public function timeout(): int
    {
        return 1800;
    }
    public function uniqueKey(): ?string
    {
        return 'search-reindex';
    }
}
