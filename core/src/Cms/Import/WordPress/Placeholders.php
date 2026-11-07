<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Dom\Comment;

/**
 * Blocks made from shortcodes before the surrounding classic text is split
 * into paragraphs. Each one stands in the text as an HTML comment
 * (<!--kestello:3-->) and is swapped back when that spot is reached.
 *
 * @internal
 */
final class Placeholders
{
    /** @var array<int, list<array<string, mixed>>> */
    private array $nodes = [];

    /**
     * @param list<array<string, mixed>> $nodes
     */
    public function add(array $nodes): string
    {
        $this->nodes[] = $nodes;

        // on its own paragraph, so it never ends up inside one
        return "\n\n<!--kestello:" . (count($this->nodes) - 1) . "-->\n\n";
    }

    public static function is(Comment $comment): bool
    {
        return preg_match('/^kestello:\d+$/', trim($comment->data)) === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function take(Comment $comment): array
    {
        if (preg_match('/^kestello:(\d+)$/', trim($comment->data), $m) !== 1) {
            return [];
        }

        return $this->nodes[(int) $m[1]] ?? [];
    }
}
