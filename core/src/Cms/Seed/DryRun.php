<?php

declare(strict_types=1);

namespace Kestello\Cms\Seed;

/**
 * Thrown inside SiteSeeder's transaction to roll back a seed that went
 * through, carrying what it would have made.
 *
 * @internal
 */
final class DryRun extends \RuntimeException
{
    /** @param array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>} $summary */
    public function __construct(public readonly array $summary)
    {
        parent::__construct('Dry run: rolled back.');
    }
}
