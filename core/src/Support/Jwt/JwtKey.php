<?php

declare(strict_types=1);

namespace Kestello\Support\Jwt;

use OpenSSLAsymmetricKey;

/**
 * A key bound to ONE algorithm. Verification refuses a token whose header
 * names another algorithm, so an RSA public key can never be used as an
 * HMAC secret (algorithm confusion).
 *
 *   JwtKey::hmac($secret)                      HS256
 *   JwtKey::fromPem($pem, 'RS256', kid: 'k1')  public (verify) or private (sign)
 *   JwtKey::fromJwk($jwk)                      from a JWKS entry (RSA, EC P-256/P-384, Ed25519)
 *   JwtKey::ed25519($secretOrPublicKey)         EdDSA with sodium
 *
 * @api
 */
final readonly class JwtKey
{
    public const array ALGORITHMS = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'];

    private function __construct(
        public string $alg,
        public string|OpenSSLAsymmetricKey $material,
        public ?string $kid = null,
        public bool $private = false,
    ) {
        if (!in_array($alg, self::ALGORITHMS, true)) {
            throw new JwtException("Unsupported algorithm \"{$alg}\".");
        }
    }

    public static function hmac(#[\SensitiveParameter] string $secret, string $alg = 'HS256', ?string $kid = null): self
    {
        if (!str_starts_with($alg, 'HS')) {
            throw new JwtException('HMAC keys need an HS* algorithm.');
        }
        if (strlen($secret) < 32) {
            throw new JwtException('HMAC secrets must be at least 32 bytes.');
        }

        return new self($alg, $secret, $kid, true);
    }

    public static function fromPem(string $pem, string $alg, ?string $kid = null, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        if (!preg_match('/^(RS|ES)\d{3}$/', $alg)) {
            throw new JwtException('PEM keys are for RS* or ES* algorithms.');
        }
        $private = str_contains($pem, 'PRIVATE KEY');
        $key = $private ? openssl_pkey_get_private($pem, $passphrase) : openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new JwtException('Invalid PEM key.');
        }
        self::assertKeyType($key, $alg);

        return new self($alg, $key, $kid, $private);
    }

    /** $key: 32-byte public key (verify) or 64-byte sodium secret key (sign). */
    public static function ed25519(string $key, ?string $kid = null): self
    {
        if (!in_array(strlen($key), [SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES], true)) {
            throw new JwtException('Ed25519 keys are 32 (public) or 64 (secret) bytes.');
        }

        return new self('EdDSA', $key, $kid, strlen($key) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
    }

    /**
     * A public key from a JWK. "alg" in the JWK wins; otherwise $defaultAlg
     * (RSA → RS256, EC → by curve, OKP → EdDSA).
     *
     * @param array<string, mixed> $jwk
     */
    public static function fromJwk(array $jwk, ?string $defaultAlg = null): self
    {
        $kty = (string) ($jwk['kty'] ?? '');
        $kid = isset($jwk['kid']) ? (string) $jwk['kid'] : null;
        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            throw new JwtException('JWK is not a signing key.');
        }

        switch ($kty) {
            case 'RSA':
                $alg = (string) ($jwk['alg'] ?? $defaultAlg ?? 'RS256');
                $n = Base64Url::decode((string) ($jwk['n'] ?? ''));
                $e = Base64Url::decode((string) ($jwk['e'] ?? ''));
                if (strlen($n) < 256) {
                    throw new JwtException('RSA keys must be at least 2048 bits.');
                }
                $key = openssl_pkey_get_public(Der::rsaPublicKeyPem($n, $e));
                break;
            case 'EC':
                $curve = (string) ($jwk['crv'] ?? '');
                $map = ['P-256' => ['prime256v1', 'ES256'], 'P-384' => ['secp384r1', 'ES384']];
                if (!isset($map[$curve])) {
                    throw new JwtException("Unsupported EC curve \"{$curve}\".");
                }
                $alg = (string) ($jwk['alg'] ?? $map[$curve][1]);
                $key = openssl_pkey_new(['ec' => [
                    'curve_name' => $map[$curve][0],
                    'x' => Base64Url::decode((string) ($jwk['x'] ?? '')),
                    'y' => Base64Url::decode((string) ($jwk['y'] ?? '')),
                ]]);
                break;
            case 'OKP':
                if (($jwk['crv'] ?? '') !== 'Ed25519') {
                    throw new JwtException('Only Ed25519 OKP keys are supported.');
                }
                return self::ed25519(Base64Url::decode((string) ($jwk['x'] ?? '')), $kid);
            default:
                throw new JwtException("Unsupported JWK key type \"{$kty}\".");
        }

        if ($key === false) {
            throw new JwtException('Invalid JWK.');
        }
        self::assertKeyType($key, $alg);

        return new self($alg, $key, $kid);
    }

    private static function assertKeyType(OpenSSLAsymmetricKey $key, string $alg): void
    {
        $type = openssl_pkey_get_details($key)['type'] ?? null;
        $expected = str_starts_with($alg, 'RS') ? OPENSSL_KEYTYPE_RSA : OPENSSL_KEYTYPE_EC;
        if ($type !== $expected) {
            throw new JwtException("The key type does not match {$alg}.");
        }
    }
}
