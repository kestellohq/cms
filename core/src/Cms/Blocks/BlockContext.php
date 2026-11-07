<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Framework\Core\Request;

/**
 * What a block tree is rendered for: the entry being viewed (or the
 * current item inside a query loop), the request, and the viewer.
 *
 * @api
 */
final class BlockContext
{
    /** Query loops per render, across nesting: keeps a page from running hundreds of queries. */
    public const int MAX_QUERIES = 10;

    private int $queries = 0;

    /**
     * @param array<string, mixed>|null $viewer the signed-in user (id, role...) or null
     */
    public function __construct(
        public readonly ?Entry $entry = null,
        public readonly ?ContentType $type = null,
        public readonly ?Request $request = null,
        public readonly ?array $viewer = null,
        public readonly int $loopDepth = 0,
        private ?self $root = null,
        /** Rendering a theme template: core/entry-content may show the entry's body. */
        public readonly bool $template = false,
        /** Someone who may design asked to see unpublished parts and patterns. */
        public readonly bool $drafts = false,
        /** Parts and synced patterns inside each other, so they can't loop. */
        public readonly int $includes = 0,
    ) {}

    /** The same context for a query loop item. */
    public function withEntry(Entry $entry, ?ContentType $type): self
    {
        return new self($entry, $type, $this->request, $this->viewer, $this->loopDepth + 1, $this->root ?? $this, $this->template, $this->drafts, $this->includes);
    }

    /** The context for a template around this entry. */
    public function forTemplate(bool $drafts = false): self
    {
        return new self($this->entry, $this->type, $this->request, $this->viewer, $this->loopDepth, $this->root ?? $this, true, $drafts, $this->includes);
    }

    /** The context for the entry's own body inside a template (it can't show itself again). */
    public function forBody(): self
    {
        return new self($this->entry, $this->type, $this->request, $this->viewer, $this->loopDepth, $this->root ?? $this, false, $this->drafts, $this->includes);
    }

    /** One level deeper into a part or pattern; null when that would go too deep. */
    public function included(): ?self
    {
        return $this->includes >= 3 ? null : new self($this->entry, $this->type, $this->request, $this->viewer, $this->loopDepth, $this->root ?? $this, $this->template, $this->drafts, $this->includes + 1);
    }

    /** Count a query loop; false when the page has run its share. */
    public function takeQuery(): bool
    {
        $root = $this->root ?? $this;

        return ++$root->queries <= self::MAX_QUERIES;
    }

    public function page(): int
    {
        return max(1, min(1000, (int) ($this->request?->query['page'] ?? 1)));
    }
}
