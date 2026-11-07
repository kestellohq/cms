<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A reviewer decided on an entry that was in review: they published or
 * scheduled it (`approved`), or sent it back to draft with a note
 * (`changes_requested`). Core notifies whoever submitted it.
 *
 * @api
 */
final readonly class EntryReviewed
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param string $title Its title.
     * @param string $decision `approved` or `changes_requested`.
     * @param ?int $reviewerId Who decided.
     * @param ?string $note What the reviewer asked for, when changes were requested.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public string $title,
        public string $decision,
        public ?int $reviewerId,
        public ?string $note = null,
    ) {}
}
