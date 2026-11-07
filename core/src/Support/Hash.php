<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * Password hashing, keyed HMACs and constant-time comparison.
 *
 * @api
 */
final class Hash
{
    /**
     * Hash a password: Argon2id when PHP has it (no length limit), bcrypt
     * otherwise. Older hashes keep verifying; needsRehash() upgrades them.
     */
    public static function password(string $password): string
    {
        return password_hash($password, self::algorithm(), self::options());
    }

    /**
     * OWASP's Argon2id baseline (19 MiB, 2 passes): strong, and light enough
     * that a burst of logins can't exhaust PHP's memory. bcrypt cost 12.
     *
     * @return array<string, int>
     * @internal
     */
    public static function options(): array
    {
        return self::algorithm() === PASSWORD_BCRYPT
            ? ['cost' => 12]
            : ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
    }

    /** @internal */
    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /** True when a stored hash should be upgraded (call after a successful verify()). */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /**
     * HMAC-SHA256 with a subkey of the app key (hex). Use it for tokens you
     * store and look up, e.g. hashing an emailed reset token.
     */
    public static function hmac(string $data, string $purpose = 'default'): string
    {
        return hash_hmac('sha256', $data, AppKey::derive('hmac.' . $purpose));
    }

    /** Check an HMAC made with the current or a previous app key. */
    public static function verifyHmac(string $data, string $mac, string $purpose = 'default'): bool
    {
        foreach ([AppKey::derive('hmac.' . $purpose), ...AppKey::derivePrevious('hmac.' . $purpose)] as $key) {
            if (hash_equals(hash_hmac('sha256', $data, $key), $mac)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Burn the same time as a real verify when there is no user, so response
     * times don't reveal which accounts exist.
     */
    public static function verifyDummy(string $password): void
    {
        static $dummy = null;
        $dummy ??= self::password('kestello-dummy-' . bin2hex(random_bytes(8)));
        password_verify($password, $dummy);
    }

    /** Constant-time string comparison. */
    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    /** Plain SHA-256 (hex), e.g. for storing high-entropy tokens. */
    public static function sha256(string $data): string
    {
        return hash('sha256', $data);
    }
}
