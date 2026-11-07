<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An entry was created or saved through the editor, the API or a plugin
 * using Entries. Fires once per save, after the database is updated; see
 * EntryPublished for the moment it becomes visible.
 *
 * @api
 */
final readonly class EntrySaved
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param string $title Its title.
     * @param string $status Its status after the save: `draft`, `pending`, `scheduled`, `published` or `private`.
     * @param bool $created Whether this save created it.
     * @param ?int $userId Who saved it.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public string $title,
        public string $status,
        public bool $created,
        public ?int $userId,
    ) {}
}
