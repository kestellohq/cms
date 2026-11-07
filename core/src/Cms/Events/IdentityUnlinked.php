<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A user removed an external sign-in identity from their account.
 *
 * @api
 */
final readonly class IdentityUnlinked
{
    /**
     * @param int $userId The user.
     * @param string $provider The sign-in provider.
     * @param int $identityId The identity that was removed.
     */
    public function __construct(
        public int $userId,
        public string $provider,
        public int $identityId,
    ) {}
}
