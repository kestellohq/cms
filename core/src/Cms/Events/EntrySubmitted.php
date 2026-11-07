<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An entry was submitted for review: its status became `pending`. Core
 * notifies the entry's assignee, or everyone who may publish it.
 *
 * @api
 */
final readonly class EntrySubmitted
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param string $title Its title.
     * @param ?int $userId Who submitted it.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public string $title,
        public ?int $userId,
    ) {}
}
