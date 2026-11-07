<?php

declare(strict_types=1);

namespace Kestello\Contracts\Search;

/**
 * One result. Engines fill id, title, url, type, publishedAt, meta and
 * body (for snippets); score is engine-specific (higher is better).
 *
 * @api
 */
final readonly class SearchHit
{
    /** @param array<string, scalar|null> $meta */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $url,
        public string $type,
        public ?int $publishedAt,
        public array $meta = [],
        public string $body = '',
        public float $score = 0.0,
    ) {}
}
