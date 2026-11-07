<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An external sign-in identity (Google, GitHub and so on, from a plugin)
 * was linked to a user.
 *
 * @api
 */
final readonly class IdentityLinked
{
    /**
     * @param int $userId The user.
     * @param string $provider The sign-in provider, such as `google`.
     * @param int $identityId The stored identity.
     * @param string $via `registration` (a new account), `profile` (the user linked it) or `email` (linked at sign-in because the verified email matched an account).
     */
    public function __construct(
        public int $userId,
        public string $provider,
        public int $identityId,
        public string $via,
    ) {}
}
