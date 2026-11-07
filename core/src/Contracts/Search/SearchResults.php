<?php

declare(strict_types=1);

namespace Kestello\Contracts\Search;

/**
 * @api
 */
final readonly class SearchResults
{
    /**
     * @param list<SearchHit> $hits
     * @param array<string, array<string, int>> $facets tag name => value => count
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $perPage,
        public array $facets = [],
        public string $engine = '',
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
