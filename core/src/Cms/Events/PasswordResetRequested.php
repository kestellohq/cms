<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A password reset link was sent. Only dispatched when the account exists,
 * so listeners never learn about unknown addresses.
 *
 * @api
 */
final readonly class PasswordResetRequested
{
    /**
     * @param int $userId The user.
     * @param string $ip The client address that asked.
     */
    public function __construct(
        public int $userId,
        public string $ip,
    ) {}
}
