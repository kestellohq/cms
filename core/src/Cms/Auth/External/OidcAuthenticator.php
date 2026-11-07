<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\AuthenticationFailed;
use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Framework\Http\Client\HttpException;
use Kestello\Support\Jwt\Jwt;
use Kestello\Support\Jwt\JwtException;
use Kestello\Support\Jwt\JwtKey;

/**
 * Base for OpenID Connect providers. Give it the issuer and credentials:
 *
 *   final class Google extends OidcAuthenticator {
 *       public function id(): string { return 'google'; }
 *       public function label(): string { return 'Continue with Google'; }
 *       public function trustsEmail(): bool { return true; }
 *       protected function issuer(): string { return 'https://accounts.google.com'; }
 *   }
 *
 * Endpoints come from the discovery document (cached a day). The ID token
 * is verified against the provider's JWKS (cached an hour, refetched at most
 * once a minute when an unknown "kid" shows up, so key rotation just works):
 * signature (RS*, ES*, EdDSA; never "none" or HMAC), iss, aud, azp, exp, iat
 * and the nonce from this attempt.
 *
 * @api
 */
abstract class OidcAuthenticator extends OAuth2Authenticator
{
    /** The issuer URL, exactly as in the discovery document's "issuer". */
    abstract protected function issuer(): string;

    /** @return list<string> */
    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    /** Algorithms accepted for ID tokens. @return list<string> */
    protected function idTokenAlgorithms(): array
    {
        return ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'];
    }

    protected function authorizationParams(AuthAttempt $attempt): array
    {
        return ['nonce' => $attempt->nonce];
    }

    protected function authorizationEndpoint(): string
    {
        return (string) $this->discovery()['authorization_endpoint'];
    }

    protected function tokenEndpoint(): string
    {
        return (string) $this->discovery()['token_endpoint'];
    }

    protected function mapUser(array $tokens, AuthAttempt $attempt): ExternalIdentity
    {
        $claims = $this->verifyIdToken((string) ($tokens['id_token'] ?? ''), $attempt);

        return $this->identityFromClaims($claims);
    }

    /** @param array<string, mixed> $claims verified ID-token claims */
    protected function identityFromClaims(array $claims): ExternalIdentity
    {
        $amr = is_array($claims['amr'] ?? null) ? $claims['amr'] : [];

        return new ExternalIdentity(
            provider: $this->id(),
            subject: (string) $claims['sub'],
            email: is_string($claims['email'] ?? null) ? $claims['email'] : null,
            emailVerified: ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true',
            name: is_string($claims['name'] ?? null) ? $claims['name'] : null,
            avatarUrl: is_string($claims['picture'] ?? null) && str_starts_with($claims['picture'], 'https://') ? $claims['picture'] : null,
            mfa: array_intersect(['mfa', 'otp', 'hwk', 'swk'], $amr) !== [],
            claims: $claims,
        );
    }

    /**
     * @return array<string, mixed> verified claims
     * @throws AuthenticationFailed
     */
    protected function verifyIdToken(string $idToken, AuthAttempt $attempt): array
    {
        if ($idToken === '') {
            throw new AuthenticationFailed(detail: 'token response has no id_token');
        }

        try {
            try {
                $claims = $this->decode($idToken, $this->keys(false));
            } catch (JwtException $e) {
                if (!str_starts_with($e->getMessage(), 'No key matches')) {
                    throw $e;
                }
                $claims = $this->decode($idToken, $this->keys(true)); // the provider rotated its keys
            }
        } catch (JwtException $e) {
            throw new AuthenticationFailed(detail: 'id_token rejected: ' . $e->getMessage(), previous: $e);
        }

        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new AuthenticationFailed(detail: 'id_token has no sub');
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($attempt->nonce, $claims['nonce'])) {
            throw new AuthenticationFailed(detail: 'id_token nonce mismatch');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if ((count($aud) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $this->clientId()) {
            throw new AuthenticationFailed(detail: 'id_token azp mismatch');
        }

        return $claims;
    }

    /**
     * @param list<JwtKey> $keys
     * @return array<string, mixed>
     */
    private function decode(string $token, array $keys): array
    {
        return Jwt::decode($token, $keys, iss: $this->issuer(), aud: $this->clientId(), maxAge: 3600);
    }

    /**
     * The discovery document.
     *
     * @return array<string, mixed>
     */
    protected function discovery(): array
    {
        $issuer = rtrim($this->issuer(), '/');
        self::assertHttps($issuer);
        $key = 'oidc:discovery:' . sha1($issuer);

        $doc = $this->cache()->get($key);
        if (!is_array($doc)) {
            try {
                $doc = $this->http()->get($issuer . '/.well-known/openid-configuration')->throw()->json();
            } catch (HttpException $e) {
                throw new AuthenticationFailed('Could not reach the sign-in provider. Please try again.', 'discovery: ' . $e->getMessage(), $e);
            }
            if (!is_array($doc) || rtrim((string) ($doc['issuer'] ?? ''), '/') !== $issuer) {
                throw new AuthenticationFailed('This sign-in provider is misconfigured.', 'discovery issuer mismatch');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                self::assertHttps((string) ($doc[$field] ?? ''));
            }
            $this->cache()->put($key, $doc, 86400);
        }

        return $doc;
    }

    /** @return list<JwtKey> */
    private function keys(bool $refresh): array
    {
        $uri = (string) $this->discovery()['jwks_uri'];
        $key = 'oidc:jwks:' . sha1($uri);
        $jwks = $this->cache()->get($key);

        if (!is_array($jwks) || ($refresh && $this->cache()->add($key . ':refreshed', 1, 60))) {
            try {
                $jwks = $this->http()->get($uri)->throw()->json();
            } catch (HttpException $e) {
                throw new AuthenticationFailed('Could not reach the sign-in provider. Please try again.', 'jwks: ' . $e->getMessage(), $e);
            }
            if (!is_array($jwks) || !is_array($jwks['keys'] ?? null)) {
                throw new AuthenticationFailed('This sign-in provider is misconfigured.', 'jwks has no keys');
            }
            $this->cache()->put($key, $jwks, 3600);
        }

        $keys = [];
        foreach ($jwks['keys'] as $jwk) {
            try {
                $k = is_array($jwk) ? JwtKey::fromJwk($jwk) : null;
                if ($k !== null && in_array($k->alg, $this->idTokenAlgorithms(), true)) {
                    $keys[] = $k;
                }
            } catch (JwtException) {
                // skip keys we can't use (encryption keys, unknown curves)
            }
        }

        return $keys;
    }
}
