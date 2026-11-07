<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A user's password changed: by the user, by an admin, or with a reset
 * link. Other sessions and tokens may have been signed out with it.
 *
 * @api
 */
final readonly class PasswordChanged
{
    /**
     * @param int $userId The user.
     * @param string $via `profile`, `reset` or `admin`.
     * @param int $sessionsRevoked Sessions signed out.
     * @param int $tokensRevoked Access tokens revoked.
     */
    public function __construct(
        public int $userId,
        public string $via,
        public int $sessionsRevoked = 0,
        public int $tokensRevoked = 0,
    ) {}
}
