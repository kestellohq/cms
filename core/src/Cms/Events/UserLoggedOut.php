<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * Someone signed out.
 *
 * @api
 */
final readonly class UserLoggedOut
{
    /**
     * @param ?int $userId The user, or null when the session had already expired.
     */
    public function __construct(
        public ?int $userId,
    ) {}
}
