<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A user account was created: by an admin, by email sign-up or by signing
 * in with a provider. Not
 * dispatched for the first admin, who is created by the installer before
 * plugins run.
 *
 * @api
 */
final readonly class UserRegistered
{
    /**
     * @param int $userId The new user.
     * @param string $via `admin`, `email` for email sign-up, or `external:<provider>` for an account created at sign-in.
     */
    public function __construct(
        public int $userId,
        public string $via,
    ) {}
}
