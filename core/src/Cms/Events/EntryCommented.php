<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * Someone left an editorial comment on an entry. Comments are only seen
 * in the admin, never on the site.
 *
 * @api
 */
final readonly class EntryCommented
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param int $commentId The comment.
     * @param ?int $userId Who wrote it.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public int $commentId,
        public ?int $userId,
    ) {}
}
