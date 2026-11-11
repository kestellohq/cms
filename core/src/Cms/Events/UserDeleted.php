<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An account was deleted: by the person themselves, from People in the admin or with
 * `user:delete`. The account is gone; remove what your plugin keeps about the person.
 *
 * @api
 */
final readonly class UserDeleted
{
    /**
     * @param int $userId The deleted account's id.
     * @param string $username Its username.
     * @param string $email Its email address.
     * @param string $via self, admin or cli.
     * @param ?int $byUserId Who deleted it, or null from the command line.
     */
    public function __construct(
        public int $userId,
        public string $username,
        public string $email,
        public string $via,
        public ?int $byUserId,
    ) {}
}
