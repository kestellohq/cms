<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * An account is about to be deleted: by the person themselves, from People in the admin or with
 * `user:delete`. A listener may stop it with a reason the person is shown, such as "Hand over
 * your organisations first": `$event->cancel('...')`. Nothing has been deleted yet.
 *
 * @api
 */
final class UserDeleting
{
    private ?string $reason = null;

    /**
     * @param int $userId The account.
     * @param string $via self, admin or cli.
     * @param ?int $byUserId Who is deleting it, or null from the command line.
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $via,
        public readonly ?int $byUserId,
    ) {}

    /** Stop the deletion; the first reason given is the one shown. */
    public function cancel(string $reason): void
    {
        $this->reason ??= trim($reason) !== '' ? trim($reason) : 'This account cannot be deleted.';
    }

    public function cancelled(): bool
    {
        return $this->reason !== null;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
