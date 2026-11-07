<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A user account was created, by an admin or by an external sign-in. Not
 * dispatched for the first admin, who is created by the installer before
 * plugins run.
 *
 * @api
 */
final readonly class UserRegistered
{
    /**
     * @param int $userId The new user.
     * @param string $via `admin`, or `external:<provider>` for an account created at sign-in.
     */
    public function __construct(
        public int $userId,
        public string $via,
    ) {}
}
