<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

/**
 * What the content converter asks about the site being imported: where an
 * old address lives now, and which media an image became.
 */
interface Links
{
    /**
     * The address to use for $url found in content: an imported item's new
     * path, an imported file's new address, or $url unchanged.
     */
    public function url(string $url): string;

    /**
     * The media library id of an imported file, found by its WordPress
     * attachment id (0 when unknown) or by its address, including the sized
     * copies WordPress makes (photo-300x200.jpg, photo-scaled.jpg). Null when
     * it was not imported.
     */
    public function media(int $attachment, string $url): ?int;

    /** Whether $url is on the old site (a page, or a file there or on the host it kept files on). */
    public function isOld(string $url): bool;

    /** The saved content of a reusable block (wp_block) in the export, or null. */
    public function reusable(int $id): ?string;
}
