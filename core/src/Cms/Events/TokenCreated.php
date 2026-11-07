<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A personal access token was created. The token itself is never part of
 * the event.
 *
 * @api
 */
final readonly class TokenCreated
{
    /**
     * @param int $userId The owner.
     * @param int $tokenId The token.
     * @param string $name The name the owner gave it.
     * @param list<string> $scopes What it may do, such as `content:read`.
     */
    public function __construct(
        public int $userId,
        public int $tokenId,
        public string $name,
        public array $scopes,
    ) {}
}
