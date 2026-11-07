<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * One of a user's sessions (devices) was signed out, or all of them except
 * the current one.
 *
 * @api
 */
final readonly class SessionRevoked
{
    /**
     * @param int $userId The user.
     * @param string $sessionId The hashed session id. With allOthers, the session that was kept.
     * @param bool $allOthers True when every other session was signed out.
     * @param int $count How many sessions were signed out.
     */
    public function __construct(
        public int $userId,
        public string $sessionId,
        public bool $allOthers = false,
        public int $count = 1,
    ) {}
}
