<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * Someone signed in.
 *
 * @api
 */
final readonly class UserLoggedIn
{
    /**
     * @param int $userId The user.
     * @param array<string, mixed> $user The public user fields (never the password or tokens).
     * @param string $method `password`, or the provider for an external sign-in, followed by `+totp` or `+recovery` when a second factor was used.
     */
    public function __construct(
        public int $userId,
        public array $user,
        public string $method,
    ) {}
}
