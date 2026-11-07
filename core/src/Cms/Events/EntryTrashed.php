<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An entry was moved to the trash. It leaves the site at once, and can be
 * restored until the trash is emptied.
 *
 * @api
 */
final readonly class EntryTrashed
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param string $title Its title.
     * @param ?int $userId Who trashed it.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public string $title,
        public ?int $userId,
    ) {}
}
