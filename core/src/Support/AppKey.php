<?php

declare(strict_types=1);

namespace Kestello\Support;

use Kestello\Framework\Security\AppKeySecretStore;
use RuntimeException;

/**
 * The site's app key, set by the kernel at boot. Helpers derive a separate
 * subkey per purpose, so a key used for signing URLs is never the same as
 * the one encrypting data.
 *
 * @internal plugins use Crypt, Hash::hmac and Url::signed instead
 */
final class AppKey
{
    private static ?string $key = null;
    /** @var list<string> */
    private static array $previous = [];
    private static ?AppKeySecretStore $store = null;

    /** @param list<string> $previous */
    public static function set(string $key, array $previous = []): void
    {
        self::$key = self::binary($key);
        self::$previous = array_values(array_map(self::binary(...), array_filter($previous, static fn($k) => is_string($k) && $k !== '')));
        self::$store = null;
    }

    public static function clear(): void
    {
        self::$key = null;
        self::$previous = [];
        self::$store = null;
    }

    public static function isSet(): bool
    {
        return self::$key !== null;
    }

    /** 32-byte subkey for $purpose from the current key. */
    public static function derive(string $purpose): string
    {
        return self::deriveFrom(self::require(), $purpose);
    }

    /** @return list<string> subkeys for $purpose from previous keys (verification after rotation) */
    public static function derivePrevious(string $purpose): array
    {
        return array_map(static fn(string $k) => self::deriveFrom($k, $purpose), self::$previous);
    }

    public static function secretStore(): AppKeySecretStore
    {
        return self::$store ??= new AppKeySecretStore(bin2hex(self::require()), array_map('bin2hex', self::$previous));
    }

    private static function deriveFrom(string $key, string $purpose): string
    {
        return function_exists('sodium_crypto_generichash')
            ? sodium_crypto_generichash('kestello:' . $purpose, $key, 32)
            : hash_hmac('sha256', 'kestello:' . $purpose, $key, true);
    }

    private static function require(): string
    {
        return self::$key ?? throw new RuntimeException('No app key is configured. Run "php bin/cms key:generate".');
    }

    private static function binary(string $key): string
    {
        $binary = ctype_xdigit($key) && strlen($key) === 64 ? (string) hex2bin($key) : $key;
        if (strlen($binary) < 32) {
            throw new \InvalidArgumentException('The app key must be at least 32 bytes (64 hex characters).');
        }

        return $binary;
    }
}
