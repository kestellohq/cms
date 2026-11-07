<?php

declare(strict_types=1);

namespace Kestello\Contracts\Search;

use InvalidArgumentException;

/**
 * One searchable item.
 *
 * `tags` are exact-match filter values ("type:post", "category:news",
 * "author:3"); `meta` is shown with results but never searched (excerpt,
 * image url, date label). Text is plain text: callers strip HTML.
 *
 * @api
 */
final readonly class SearchDocument
{
    public const int MAX_BODY = 60_000;

    /**
     * @param list<string> $tags
     * @param array<string, scalar|null> $meta
     */
    public function __construct(
        public string $collection,
        public string $id,
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public string $type = '',
        public ?int $publishedAt = null,
        public array $tags = [],
        public array $meta = [],
        public string $locale = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $collection) !== 1) {
            throw new InvalidArgumentException("Search collections are lowercase names: \"{$collection}\".");
        }
        if (preg_match('/^[A-Za-z0-9_:.-]{1,64}$/', $id) !== 1) {
            throw new InvalidArgumentException("Search document ids are 1-64 letters, digits, _ : . or -: \"{$id}\".");
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match('/^[a-z0-9_-]{1,32}:[A-Za-z0-9_.-]{1,64}$/', $tag) !== 1) {
                throw new InvalidArgumentException('Search tags are "name:value" ("category:news").');
            }
        }
    }

    /** The same document with text cut to engine-friendly sizes. */
    public function trimmed(): self
    {
        return new self(
            $this->collection,
            $this->id,
            mb_substr(trim($this->title), 0, 255),
            mb_substr(trim($this->body), 0, self::MAX_BODY),
            $this->url,
            $this->type,
            $this->publishedAt,
            array_values(array_unique($this->tags)),
            $this->meta,
            $this->locale,
        );
    }
}
