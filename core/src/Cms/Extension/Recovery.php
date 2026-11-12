<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * Signed, expiring recovery tokens.
 *
 * Recovery mode boots the site with every plugin disabled and the base
 * theme active, so an admin can get in and fix a site that a package broke.
 * Tokens are HMAC-SHA256 signed with the application secret key.
 */
final class Recovery
{
    public const string QUERY = 'kestello-recovery';
    public const string COOKIE = 'kestello_recovery';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Recovery mode needs app.secret_key (at least 32 characters). Run php bin/cms key:generate.');
        }
    }

    public function createToken(int $ttlSeconds = 3600, ?int $now = null): string
    {
        $expires = ($now ?? time()) + $ttlSeconds;

        return $expires . '.' . $this->sign($expires);
    }

    public function verify(#[\SensitiveParameter] string $token, ?int $now = null): bool
    {
        if (preg_match('/^(\d{10,})\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return false;
        }

        $expires = (int) $m[1];

        return $expires > ($now ?? time()) && hash_equals($this->sign($expires), $m[2]);
    }

    public function expiresAt(#[\SensitiveParameter] string $token): ?int
    {
        return preg_match('/^(\d{10,})\./', $token, $m) === 1 ? (int) $m[1] : null;
    }

    private function sign(int $expires): string
    {
        $mac = hash_hmac('sha256', 'kestello-recovery|' . $expires, $this->secret, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
