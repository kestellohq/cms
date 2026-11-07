<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\AuthenticationFailed;
use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Contracts\Auth\RedirectAuthenticator;
use Kestello\Contracts\Cache\Store as CacheStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpException;

/**
 * Base for OAuth 2 authorization-code providers (with PKCE, always).
 * Subclasses give the endpoints, scopes and mapUser():
 *
 *   final class GitHub extends OAuth2Authenticator {
 *       public function id(): string { return 'github'; }
 *       public function label(): string { return 'Continue with GitHub'; }
 *       protected function authorizationEndpoint(): string { return 'https://github.com/login/oauth/authorize'; }
 *       protected function tokenEndpoint(): string { return 'https://github.com/login/oauth/access_token'; }
 *       protected function scopes(): array { return ['read:user', 'user:email']; }
 *       protected function mapUser(array $tokens, AuthAttempt $attempt): ExternalIdentity { ... $this->getJson(...) ... }
 *   }
 *   $cms->auth->authenticator(new GitHub(['client_id' => ..., 'client_secret' => ...]));
 *
 * Core validates state, expiry and the session binding before complete()
 * runs, and runs it as the registering plugin, so the HTTP client only
 * reaches the hosts in that plugin's "permissions.network".
 *
 * @api
 */
abstract class OAuth2Authenticator implements RedirectAuthenticator
{
    private ?HttpClient $http;
    private ?CacheStore $cache;

    /** @param array{client_id?: string, client_secret?: string} $config */
    public function __construct(protected readonly array $config = [], ?HttpClient $http = null, ?CacheStore $cache = null)
    {
        $this->http = $http;
        $this->cache = $cache;
    }

    abstract protected function authorizationEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /**
     * Turn the token response into an identity (usually by calling a userinfo API).
     *
     * @param array<string, mixed> $tokens decoded token response (access_token, id_token…)
     * @throws AuthenticationFailed
     */
    abstract protected function mapUser(array $tokens, AuthAttempt $attempt): ExternalIdentity;

    /** @return list<string> */
    protected function scopes(): array
    {
        return [];
    }

    /** Extra query parameters for the authorization URL (prompt, hd…). @return array<string, string> */
    protected function authorizationParams(AuthAttempt $attempt): array
    {
        return [];
    }

    /** "client_secret_post" (default) or "client_secret_basic". */
    protected function tokenAuthMethod(): string
    {
        return 'client_secret_post';
    }

    public function icon(): ?string
    {
        return null;
    }

    public function trustsEmail(): bool
    {
        return false;
    }

    /** Whether client credentials are set (core hides unconfigured providers). */
    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function authorizationUrl(AuthAttempt $attempt): string
    {
        $endpoint = $this->authorizationEndpoint();
        self::assertHttps($endpoint);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $attempt->redirectUri,
            'state' => $attempt->state,
            'code_challenge' => $attempt->pkceChallenge(),
            'code_challenge_method' => 'S256',
        ];
        if ($this->scopes() !== []) {
            $params['scope'] = implode(' ', $this->scopes());
        }
        $params += $this->authorizationParams($attempt);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(Request $request, AuthAttempt $attempt): ExternalIdentity
    {
        $error = $request->input('error');
        if (is_string($error) && $error !== '') {
            throw new AuthenticationFailed(
                $error === 'access_denied' ? 'Sign-in was cancelled.' : 'The provider refused the sign-in. Please try again.',
                'provider error: ' . mb_substr($error . ' ' . (string) $request->input('error_description', ''), 0, 300),
            );
        }

        $code = $request->input('code');
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            throw new AuthenticationFailed(detail: 'callback without a code');
        }

        return $this->mapUser($this->exchangeCode($code, $attempt), $attempt);
    }

    /** @return array<string, mixed> */
    protected function exchangeCode(string $code, AuthAttempt $attempt): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $attempt->redirectUri,
            'code_verifier' => $attempt->pkceVerifier,
        ];
        $options = ['headers' => ['Accept' => 'application/json']];
        if ($this->tokenAuthMethod() === 'client_secret_basic') {
            $options['basic'] = [rawurlencode($this->clientId()), rawurlencode($this->clientSecret())];
        } else {
            $form += ['client_id' => $this->clientId(), 'client_secret' => $this->clientSecret()];
        }
        $options['form'] = $form;

        $endpoint = $this->tokenEndpoint();
        self::assertHttps($endpoint);
        try {
            $response = $this->http()->post($endpoint, $options);
            $tokens = $response->json();
        } catch (HttpException $e) {
            throw new AuthenticationFailed('Could not reach the provider. Please try again.', 'token request: ' . $e->getMessage(), $e);
        }

        if (!is_array($tokens) || !$response->ok() || isset($tokens['error']) || !is_string($tokens['access_token'] ?? null)) {
            $reason = is_array($tokens) ? (string) ($tokens['error'] ?? 'no access_token') : 'not an object';
            throw new AuthenticationFailed(detail: "token endpoint answered HTTP {$response->status}: " . mb_substr($reason, 0, 200));
        }

        return $tokens;
    }

    /**
     * GET a JSON API with the access token.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function getJson(string $url, string $accessToken, array $headers = []): array
    {
        try {
            $data = $this->http()->get($url, ['bearer' => $accessToken, 'headers' => $headers])->throw()->json();
        } catch (HttpException $e) {
            throw new AuthenticationFailed('Could not read your profile from the provider. Please try again.', $e->getMessage(), $e);
        }

        return is_array($data) ? $data : throw new AuthenticationFailed(detail: "{$url} did not return an object");
    }

    protected function clientId(): string
    {
        return (string) ($this->config['client_id'] ?? '');
    }

    protected function clientSecret(): string
    {
        return (string) ($this->config['client_secret'] ?? '');
    }

    protected function http(): HttpClient
    {
        return $this->http ??= self::service(HttpClient::class) ?? new HttpClient();
    }

    protected function cache(): CacheStore
    {
        return $this->cache ??= self::service(CacheStore::class) ?? new ArrayStore();
    }

    protected static function assertHttps(string $url): void
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new AuthenticationFailed('This sign-in provider is misconfigured.', "endpoint is not https: {$url}");
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private static function service(string $class): ?object
    {
        $container = Cms::instance()?->container;

        return $container !== null && $container->has($class) ? $container->make($class) : null;
    }
}
