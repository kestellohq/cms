<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A user finished setting up a second factor.
 *
 * @api
 */
final readonly class TwoFactorEnabled
{
    /**
     * @param int $userId The user.
     * @param string $type The kind of factor, such as `totp`.
     */
    public function __construct(
        public int $userId,
        public string $type,
    ) {}
}
