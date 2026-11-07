<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A user's second factor was removed: by the user, or reset with
 * `auth:reset-2fa`.
 *
 * @api
 */
final readonly class TwoFactorDisabled
{
    /**
     * @param int $userId The user.
     * @param ?int $byUserId Who removed it, or null from the command line.
     */
    public function __construct(
        public int $userId,
        public ?int $byUserId,
    ) {}
}
