<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A sign-in attempt failed: an unknown account, a wrong password, an
 * inactive account, too many attempts, or a wrong second factor.
 *
 * @api
 */
final readonly class LoginFailed
{
    /**
     * @param string $identifier What was typed as the username or email.
     * @param ?int $userId The account, when it exists.
     * @param string $reason `credentials`, `throttled` or `two_factor`.
     * @param string $ip The client address.
     */
    public function __construct(
        public string $identifier,
        public ?int $userId,
        public string $reason,
        public string $ip,
    ) {}
}
