<?php

declare(strict_types=1);

namespace Kestello\Cms\Package;

use Kestello\Framework\Log\Log;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipReader;
use RuntimeException;

/**
 * Ed25519 signatures for package zips, carried inside the zip as SIGNATURE,
 * so a package stays one file (uploads, registry downloads).
 *
 * What is signed: a domain line + the SHA-256 of a listing of every other
 * file ("<sha256> <name>\n", sorted by name). Changing, adding or removing
 * any file breaks the signature. The domain is "kestello-package-v1\n" for
 * plugins and themes and "kestello-core-v1\n" for core releases, so a
 * package signature never passes as a core one, even under the same key.
 *
 * Trusted public keys come from the server's config (app.packages.trusted_keys, and
 * release.keys for core releases); never from the admin.
 *
 *   SIGNATURE: {"format":1,"alg":"ed25519","key":"<key id>","digest":"<hex>","signature":"<base64>","signed_at":"…"}
 */
final class PackageSignature
{
    public const string FILE = 'SIGNATURE';
    public const string PACKAGE = 'package';
    public const string CORE = 'core';
    private const array DOMAINS = [self::PACKAGE => "kestello-package-v1\n", self::CORE => "kestello-core-v1\n"];
    private const int MAX_ENTRY = 128 << 20;

    public const string SIGNED = 'signed';          // by a trusted key
    public const string UNSIGNED = 'unsigned';
    public const string UNTRUSTED = 'untrusted';    // a valid signature by a key this site doesn't trust
    public const string TAMPERED = 'tampered';      // the signature doesn't match the files

    /** @return array{id: string, public_key: string, secret_key: string} base64 keys */
    public static function generateKeys(): array
    {
        self::requireSodium();
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);

        return ['id' => self::keyId($public), 'public_key' => base64_encode($public), 'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair))];
    }

    public static function keyId(string $rawPublicKey): string
    {
        return substr(hash('sha256', $rawPublicKey), 0, 16);
    }

    /** The digest the signature covers. */
    public static function digest(ZipReader $zip): string
    {
        $lines = [];
        foreach ($zip->entries() as $entry) {
            if ($entry->isDirectory || $entry->name === self::FILE) {
                continue;
            }
            $lines[$entry->name] = hash('sha256', $zip->read($entry->name, self::MAX_ENTRY)) . ' ' . $entry->name . "\n";
        }
        ksort($lines, SORT_STRING);

        return hash('sha256', implode('', $lines));
    }

    /** Sign $zipPath in place (an existing signature is replaced). $kind: PACKAGE or CORE. */
    public static function sign(string $zipPath, string $secretKeyBase64, string $kind = self::PACKAGE): void
    {
        $domain = self::domain($kind);
        self::requireSodium();
        $secret = base64_decode($secretKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('That is not an Ed25519 secret key.');
        }
        $in = Zip::open($zipPath);
        $digest = self::digest($in);
        $signature = [
            'format' => 1,
            'alg' => 'ed25519',
            'key' => self::keyId(sodium_crypto_sign_publickey_from_secretkey($secret)),
            'digest' => $digest,
            'signature' => base64_encode(sodium_crypto_sign_detached($domain . $digest, $secret)),
            'signed_at' => gmdate('c'),
        ];

        $out = Zip::create($zipPath . '.signing');
        foreach ($in->entries() as $entry) {
            if (!$entry->isDirectory && $entry->name !== self::FILE) {
                $out->addString($entry->name, $in->read($entry->name, self::MAX_ENTRY));
            }
        }
        $out->addString(self::FILE, json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $out->close();
        unset($in);
        rename($zipPath . '.signing', $zipPath);
    }

    /**
     * @param array<string, array{name: string, public_key: string}> $trusted key id => key
     * @param string $kind PACKAGE or CORE: what the zip must have been signed as
     * @return array{status: string, key: ?string, name: ?string}
     */
    public static function verify(ZipReader $zip, array $trusted, string $kind = self::PACKAGE): array
    {
        $domain = self::domain($kind);
        if (!$zip->has(self::FILE)) {
            return ['status' => self::UNSIGNED, 'key' => null, 'name' => null];
        }
        $sig = json_decode($zip->read(self::FILE, 8192), true);
        if (!is_array($sig) || ($sig['alg'] ?? '') !== 'ed25519' || !is_string($sig['key'] ?? null) || !is_string($sig['signature'] ?? null)) {
            return ['status' => self::TAMPERED, 'key' => null, 'name' => null];
        }
        $digest = self::digest($zip);
        if (!hash_equals($digest, (string) ($sig['digest'] ?? ''))) {
            return ['status' => self::TAMPERED, 'key' => $sig['key'], 'name' => null];
        }
        $key = $trusted[$sig['key']] ?? null;
        if ($key === null) {
            return ['status' => self::UNTRUSTED, 'key' => $sig['key'], 'name' => null];
        }
        self::requireSodium();
        $public = base64_decode($key['public_key'], true);
        $raw = base64_decode($sig['signature'], true);
        $ok = $public !== false && $raw !== false && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($raw, $domain . $digest, $public);

        return ['status' => $ok ? self::SIGNED : self::TAMPERED, 'key' => $sig['key'], 'name' => $ok ? $key['name'] : null];
    }

    /**
     * Keys from config: a list of {name, public_key} (base64). Bad entries are skipped and logged.
     *
     * @param mixed $config
     * @param string $setting the config key, named in the log when an entry is skipped
     * @return array<string, array{name: string, public_key: string}>
     */
    public static function trustedKeys(mixed $config, string $setting = 'app.packages.trusted_keys'): array
    {
        $out = [];
        foreach (is_array($config) ? $config : [] as $entry) {
            $public = is_array($entry) && is_string($entry['public_key'] ?? null) ? base64_decode($entry['public_key'], true) : false;
            if ($public === false || strlen($public) !== 32) {
                Log::warning("{$setting}: an entry is not a base64 Ed25519 public key and is ignored.");
                continue;
            }
            $out[self::keyId($public)] = ['name' => mb_substr((string) ($entry['name'] ?? 'Unnamed key'), 0, 80), 'public_key' => (string) $entry['public_key']];
        }

        return $out;
    }

    private static function domain(string $kind): string
    {
        return self::DOMAINS[$kind] ?? throw new \InvalidArgumentException("Unknown signature kind: {$kind}.");
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The PHP sodium extension is needed to sign and verify packages.');
        }
    }
}
