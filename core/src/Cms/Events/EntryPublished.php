<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An entry became visible to visitors: it was published, or a scheduled
 * entry reached its time. It is plain data, so listeners may run in the
 * background with #[Queued].
 *
 * @api
 */
final readonly class EntryPublished
{
    /**
     * @param int $entryId The entry.
     * @param string $type Its content type, such as `post`.
     * @param string $title Its title.
     * @param ?string $path Its address on the site, or null for a type without public pages.
     */
    public function __construct(
        public int $entryId,
        public string $type,
        public string $title,
        public ?string $path,
    ) {}
}
