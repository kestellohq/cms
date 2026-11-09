<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use InvalidArgumentException;
use Kestello\Cms\Package\PackageSignature;
use RuntimeException;

/**
 * The signed wrapper around keys.json, the index and every certificate (registry-format.md §3.1).
 * The signature covers a domain line and the payload's exact bytes, so no canonical JSON is needed.
 *
 *   {"format": 1, "payload": "<base64 JSON>", "signatures": [{"alg": "ed25519", "key": "<id>", "sig": "<base64>"}]}
 *
 * The domains keep kinds apart: a key signature never passes as an index one, and neither passes as
 * a package or core release signature (kestello-package-v1, kestello-core-v1).
 *
 * @internal
 */
final class Envelope
{
    public const string INDEX = 'index';
    public const string KEY = 'key';
    private const array DOMAINS = [self::INDEX => "kestello-registry-v1\n", self::KEY => "kestello-key-v1\n"];
    private const int MAX_SIGNATURES = 8;

    /**
     * The payload's bytes, when at least one signature is by one of $keys and verifies.
     *
     * @param array<string, string> $keys key id => raw public key
     * @throws RegistryException
     */
    public static function open(mixed $envelope, array $keys, string $kind, string $what): string
    {
        $domain = self::domain($kind);
        if (!is_array($envelope) || ($envelope['format'] ?? null) !== 1 || !is_string($envelope['payload'] ?? null)) {
            throw new RegistryException("The registry's {$what} is not a signed registry file (format 1).");
        }
        $signatures = $envelope['signatures'] ?? null;
        if (!is_array($signatures) || !array_is_list($signatures) || $signatures === [] || count($signatures) > self::MAX_SIGNATURES) {
            throw new RegistryException("The registry's {$what} has no signatures, or too many.");
        }
        $payload = base64_decode($envelope['payload'], true);
        if ($payload === false) {
            throw new RegistryException("The registry's {$what} is damaged: its contents are not base64.");
        }
        self::requireSodium();
        foreach ($signatures as $signature) {
            // other algorithms are skipped, so a later one can sign beside Ed25519
            if (!is_array($signature) || ($signature['alg'] ?? null) !== 'ed25519') {
                continue;
            }
            $public = is_string($signature['key'] ?? null) ? ($keys[$signature['key']] ?? null) : null;
            $raw = is_string($signature['sig'] ?? null) ? base64_decode($signature['sig'], true) : false;
            if ($public === null || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            if (sodium_crypto_sign_verify_detached($raw, $domain . $payload, $public)) {
                return $payload;
            }
        }

        throw new RegistryException("The registry's {$what} is not signed by a key Kestello trusts for it.");
    }

    /**
     * Wrap $payload, signed by each of $secretKeys (base64 Ed25519 secret keys). For the registry
     * builder and tests; sites only open envelopes.
     *
     * @param list<string> $secretKeys
     * @return array{format: int, payload: string, signatures: list<array{alg: string, key: string, sig: string}>}
     */
    public static function seal(string $payload, array $secretKeys, string $kind): array
    {
        $domain = self::domain($kind);
        self::requireSodium();
        $signatures = [];
        foreach ($secretKeys as $secretKey) {
            $secret = base64_decode($secretKey, true);
            if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new InvalidArgumentException('That is not an Ed25519 secret key.');
            }
            $signatures[] = [
                'alg' => 'ed25519',
                'key' => PackageSignature::keyId(sodium_crypto_sign_publickey_from_secretkey($secret)),
                'sig' => base64_encode(sodium_crypto_sign_detached($domain . $payload, $secret)),
            ];
        }

        return ['format' => 1, 'payload' => base64_encode($payload), 'signatures' => $signatures];
    }

    private static function domain(string $kind): string
    {
        return self::DOMAINS[$kind] ?? throw new InvalidArgumentException("Unknown registry signature kind: {$kind}.");
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('The PHP sodium extension is needed to check the registry.');
        }
    }
}
