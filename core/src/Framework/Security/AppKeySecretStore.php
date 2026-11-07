<?php

declare(strict_types=1);

namespace Kestello\Framework\Security;

use InvalidArgumentException;
use Kestello\Contracts\Security\SecretStore;
use RuntimeException;

/**
 * Secrets encrypted with the app key (libsodium XSalsa20-Poly1305).
 *
 * Format: "kst1:<key id>:<base64url(nonce . ciphertext)>". The key used for
 * each context is derived from the app key with BLAKE2b, so contexts can't
 * open each other's values. Old app keys in app.previous_keys still open
 * values sealed before a key rotation; reseal() upgrades them.
 */
final class AppKeySecretStore implements SecretStore
{
    private const string PREFIX = 'kst1:';

    /** @var array<string, string> key id => 32-byte master key */
    private array $keys = [];
    private string $currentId;

    /** @param list<string> $previousKeys */
    public function __construct(string $appKey, array $previousKeys = [])
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('The sodium extension is required for encrypted secrets.');
        }

        $this->currentId = $this->addKey($appKey);
        foreach ($previousKeys as $key) {
            if (is_string($key) && $key !== '') {
                $this->addKey($key);
            }
        }
    }

    public function seal(string $plaintext, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->derive($this->currentId, $context));

        return self::PREFIX . $this->currentId . ':' . rtrim(strtr(base64_encode($nonce . $box), '+/', '-_'), '=');
    }

    public function open(string $sealed, string $context): string
    {
        $parts = explode(':', $sealed, 3);
        if (count($parts) !== 3 || $parts[0] . ':' !== self::PREFIX) {
            throw new RuntimeException('Not a sealed secret.');
        }
        [, $id, $payload] = $parts;

        if (!isset($this->keys[$id])) {
            throw new RuntimeException('This secret was sealed with an unknown app key. Re-enter it, or add the old key to app.previous_keys.');
        }

        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Malformed sealed secret.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->derive($id, $context),
        );

        if ($plain === false) {
            throw new RuntimeException('Sealed secret failed authentication (tampered, or wrong context).');
        }

        return $plain;
    }

    public function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) && substr_count($value, ':') === 2;
    }

    /** Whether $sealed uses the current key (false = reseal after rotation). */
    public function isCurrent(string $sealed): bool
    {
        return str_starts_with($sealed, self::PREFIX . $this->currentId . ':');
    }

    public function reseal(string $sealed, string $context): string
    {
        return $this->isCurrent($sealed) ? $sealed : $this->seal($this->open($sealed, $context), $context);
    }

    private function addKey(string $key): string
    {
        $binary = ctype_xdigit($key) && strlen($key) === 64 ? hex2bin($key) : $key;
        if (strlen($binary) < 32) {
            throw new InvalidArgumentException('The app key must be at least 32 bytes (64 hex characters). Run "php bin/cms key:generate".');
        }

        $id = substr(bin2hex(sodium_crypto_generichash('kestello:key-id', $binary, 16)), 0, 8);
        $this->keys[$id] = $binary;

        return $id;
    }

    private function derive(string $id, string $context): string
    {
        return sodium_crypto_generichash('kestello:secret:' . $context, $this->keys[$id], SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
