<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An entry was assigned to someone, or unassigned.
 *
 * @api
 */
final readonly class EntryAssigned
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param ?int $assigneeId Who it is assigned to now, or null when nobody.
     * @param ?int $userId Who assigned it.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public ?int $assigneeId,
        public ?int $userId,
    ) {}
}
