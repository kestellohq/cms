<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A personal access token was revoked, or all of a user's tokens were.
 *
 * @api
 */
final readonly class TokenRevoked
{
    /**
     * @param int $userId The owner.
     * @param ?int $tokenId The token, or null when all of them were revoked.
     */
    public function __construct(
        public int $userId,
        public ?int $tokenId,
    ) {}
}
