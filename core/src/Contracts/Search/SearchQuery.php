<?php

declare(strict_types=1);

namespace Kestello\Contracts\Search;

/**
 * What to look for. `tags` are ANDed between names and ORed within one
 * name: ["type:post", "type:page", "category:news"] means (post or page)
 * and category news. `facets` names the tag names to count ("type").
 *
 * @api
 */
final readonly class SearchQuery
{
    public const int MAX_TEXT = 200;
    public const int MAX_PER_PAGE = 50;

    public string $text;
    public int $page;
    public int $perPage;

    /**
     * @param list<string> $tags
     * @param list<string> $facets
     */
    public function __construct(
        public string $collection,
        string $text,
        public array $tags = [],
        int $page = 1,
        int $perPage = 10,
        public array $facets = [],
        public ?int $now = null,
        public string $locale = '',
    ) {
        $this->text = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, self::MAX_TEXT);
        $this->page = max(1, min(500, $page));
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
    }

    /** Words worth matching: letters and digits only (no engine operators), at most 10. @return list<string> */
    public function terms(): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($this->text), $m);

        return array_slice(array_values(array_unique($m[0])), 0, 10);
    }

    /** @return array<string, list<string>> tag name => accepted values */
    public function tagGroups(): array
    {
        $groups = [];
        foreach ($this->tags as $tag) {
            [$name, $value] = explode(':', $tag, 2) + [1 => ''];
            $groups[$name][] = $value;
        }

        return $groups;
    }

    public function now(): int
    {
        return $this->now ?? time();
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
