<?php

declare(strict_types=1);

namespace Kestello\Support\Jwt;

use Kestello\Support\Clock;
use Kestello\Support\Json;

/**
 * JSON Web Tokens (JWS compact form).
 *
 *   $token = Jwt::encode(['sub' => '5', 'exp' => time() + 300], JwtKey::hmac($secret));
 *   $claims = Jwt::decode($token, [JwtKey::hmac($secret)], iss: 'https://example.com', aud: 'my-app');
 *
 * decode() verifies the signature with the key matching "kid" (or the only
 * key of that algorithm), refuses "none" and any algorithm the key isn't
 * bound to, and checks exp/nbf/iat (60 s leeway), iss and aud. Tokens
 * without "exp" are refused unless $requireExp is false.
 *
 * @api
 */
final class Jwt
{
    private const array HASH = ['256' => 'sha256', '384' => 'sha384', '512' => 'sha512'];

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header extra header fields
     */
    public static function encode(array $claims, JwtKey $key, array $header = []): string
    {
        if (!$key->private) {
            throw new JwtException('Signing needs a private key.');
        }

        $header = ['typ' => 'JWT', 'alg' => $key->alg] + ($key->kid !== null ? ['kid' => $key->kid] : []) + $header;
        $input = Base64Url::encode(Json::encode($header)) . '.' . Base64Url::encode(Json::encode((object) $claims));

        return $input . '.' . Base64Url::encode(self::sign($input, $key));
    }

    /**
     * Unverified header, e.g. to pick a key set by "kid". Never trust it alone.
     *
     * @return array<string, mixed>
     */
    public static function header(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Malformed token.');
        }
        $header = Json::tryDecode(Base64Url::decode($parts[0]));

        return is_array($header) ? $header : throw new JwtException('Malformed token header.');
    }

    /**
     * @param list<JwtKey>        $keys
     * @param string|list<string>|null $aud the token's "aud" must contain one of these
     * @return array<string, mixed> verified claims
     */
    public static function decode(
        string $token,
        array $keys,
        ?string $iss = null,
        string|array|null $aud = null,
        int $leeway = 60,
        bool $requireExp = true,
        ?int $maxAge = null,
    ): array {
        if (strlen($token) > 16384) {
            throw new JwtException('Token too large.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Malformed token.');
        }

        $header = self::header($token);
        $alg = (string) ($header['alg'] ?? '');
        if ($alg === '' || strtolower($alg) === 'none' || !in_array($alg, JwtKey::ALGORITHMS, true)) {
            throw new JwtException("Refused algorithm \"{$alg}\".");
        }
        if (isset($header['crit'])) {
            throw new JwtException('Unsupported critical header.');
        }

        $candidates = array_values(array_filter($keys, static fn(JwtKey $k) => $k->alg === $alg));
        $kid = isset($header['kid']) ? (string) $header['kid'] : null;
        if ($kid !== null) {
            $candidates = array_values(array_filter($candidates, static fn(JwtKey $k) => $k->kid === $kid));
        } elseif (count($candidates) > 1) {
            throw new JwtException('Token has no "kid" and several keys match.');
        }
        if ($candidates === []) {
            throw new JwtException('No key matches the token' . ($kid !== null ? " (kid \"{$kid}\")" : '') . '.');
        }

        if (!self::verify($parts[0] . '.' . $parts[1], Base64Url::decode($parts[2]), $candidates[0])) {
            throw new JwtException('Signature verification failed.');
        }

        $claims = Json::tryDecode(Base64Url::decode($parts[1]));
        if (!is_array($claims) || array_is_list($claims) && $claims !== []) {
            throw new JwtException('Malformed claims.');
        }

        $now = Clock::timestamp();
        if (isset($claims['exp'])) {
            if (!is_numeric($claims['exp']) || $now - $leeway >= (int) $claims['exp']) {
                throw new JwtException('Token expired.');
            }
        } elseif ($requireExp) {
            throw new JwtException('Token has no expiry.');
        }
        if (isset($claims['nbf']) && (!is_numeric($claims['nbf']) || $now + $leeway < (int) $claims['nbf'])) {
            throw new JwtException('Token not valid yet.');
        }
        if (isset($claims['iat'])) {
            if (!is_numeric($claims['iat']) || (int) $claims['iat'] > $now + $leeway) {
                throw new JwtException('Token issued in the future.');
            }
            if ($maxAge !== null && $now - (int) $claims['iat'] > $maxAge + $leeway) {
                throw new JwtException('Token too old.');
            }
        } elseif ($maxAge !== null) {
            throw new JwtException('Token has no "iat".');
        }
        if ($iss !== null && ($claims['iss'] ?? null) !== $iss) {
            throw new JwtException('Unexpected issuer.');
        }
        if ($aud !== null) {
            $tokenAud = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
            if (array_intersect((array) $aud, $tokenAud) === []) {
                throw new JwtException('Unexpected audience.');
            }
        }

        return $claims;
    }

    private static function sign(string $input, JwtKey $key): string
    {
        $bits = substr($key->alg, 2);

        return match (substr($key->alg, 0, 2)) {
            'HS' => hash_hmac(self::HASH[$bits], $input, (string) $key->material, true),
            'RS', 'ES' => self::opensslSign($input, $key, $bits),
            default => sodium_crypto_sign_detached($input, (string) $key->material),
        };
    }

    private static function opensslSign(string $input, JwtKey $key, string $bits): string
    {
        if (!openssl_sign($input, $signature, $key->material, self::HASH[$bits])) {
            throw new JwtException('Signing failed.');
        }

        return str_starts_with($key->alg, 'ES') ? Der::ecdsaToRaw($signature, $bits === '256' ? 32 : 48) : $signature;
    }

    private static function verify(string $input, string $signature, JwtKey $key): bool
    {
        $bits = substr($key->alg, 2);

        switch (substr($key->alg, 0, 2)) {
            case 'HS':
                return hash_equals(hash_hmac(self::HASH[$bits], $input, (string) $key->material, true), $signature);
            case 'RS':
                return openssl_verify($input, $signature, $key->material, self::HASH[$bits]) === 1;
            case 'ES':
                try {
                    $der = Der::ecdsaFromRaw($signature, $bits === '256' ? 32 : 48);
                } catch (JwtException) {
                    return false;
                }
                return openssl_verify($input, $der, $key->material, self::HASH[$bits]) === 1;
            default:
                $public = strlen((string) $key->material) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
                    ? sodium_crypto_sign_publickey_from_secretkey((string) $key->material)
                    : (string) $key->material;
                return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature, $input, $public);
        }
    }
}
