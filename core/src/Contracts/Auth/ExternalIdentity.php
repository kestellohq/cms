<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

/**
 * Who the provider says the user is. (provider, subject) is the stable key;
 * emails change and are only trusted when $emailVerified and the
 * authenticator trustsEmail(). $claims stay in memory and are never stored.
 *
 * @api
 */
final readonly class ExternalIdentity
{
    /** @param array<string, mixed> $claims */
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email = null,
        public bool $emailVerified = false,
        public ?string $name = null,
        public ?string $avatarUrl = null,
        public bool $mfa = false,
        public array $claims = [],
    ) {
        if ($subject === '' || strlen($subject) > 191) {
            throw new \InvalidArgumentException('Identity subjects are 1–191 characters.');
        }
    }
}
