<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\External\ExternalLogin;
use Kestello\Cms\Auth\External\ExternalLoginResult;
use Kestello\Cms\Auth\External\OAuth2Authenticator;
use Kestello\Cms\Auth\External\OidcAuthenticator;
use Kestello\Cms\Auth\External\ReturnPath;
use Kestello\Cms\Auth\Totp;
use Kestello\Cms\Auth\TwoFactor;
use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Support\Jwt\Base64Url;
use Kestello\Support\Jwt\Jwt;
use Kestello\Support\Jwt\JwtKey;

/** A test OIDC provider at https://idp.example. */
final class TestIdp extends OidcAuthenticator
{
    public bool $trusts = true;
    public function id(): string
    {
        return 'idp';
    }
    public function label(): string
    {
        return 'Continue with TestIdP';
    }
    public function trustsEmail(): bool
    {
        return $this->trusts;
    }
    protected function issuer(): string
    {
        return 'https://idp.example';
    }
}

/** External sign-in: OIDC flow, attempt binding, linking policy, second factor. */
final class ExternalAuthTest extends AuthTestCase
{
    private FakeTransport $fake;
    private TestIdp $idp;
    /** @var array{0: string, 1: string} kid, private PEM */
    private array $signing;
    /** @var array<string, mixed> claims the token endpoint puts in the next id_token */
    private array $claims = [];
    private string $tokenKid = 'k1';
    private string $nonce = '';
    private string $rotatedPem = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms->config->set('app.url', 'https://site.example');

        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $pem);
        $this->signing = ['k1', $pem];
        $jwk = static function (string $kid, \OpenSSLAsymmetricKey $key): array {
            $d = openssl_pkey_get_details($key)['ec'];
            return ['kty' => 'EC', 'crv' => 'P-256', 'kid' => $kid, 'use' => 'sig', 'x' => Base64Url::encode($d['x']), 'y' => Base64Url::encode($d['y'])];
        };
        $rotated = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($rotated, $rotatedPem);
        $this->rotatedPem = $rotatedPem;
        $jwksCalls = 0;

        $this->fake = new FakeTransport([
            'GET https://idp.example/.well-known/openid-configuration' => ['json' => [
                'issuer' => 'https://idp.example',
                'authorization_endpoint' => 'https://idp.example/authorize',
                'token_endpoint' => 'https://idp.example/token',
                'jwks_uri' => 'https://idp.example/jwks',
            ]],
            'GET https://idp.example/jwks' => function () use (&$jwksCalls, $pk, $rotated, $rotatedPem, $jwk) {
                $jwksCalls++;
                $keys = [$jwk('k1', $pk)];
                if ($this->tokenKid === 'k2') {
                    $keys[] = $jwk('k2', $rotated);   // published after rotation
                }
                return ['json' => ['keys' => $keys]];
            },
            'POST https://idp.example/token' => function (PendingRequest $r) {
                parse_str((string) $r->body, $form);
                $this->lastTokenForm = $form;
                if (($form['code'] ?? '') !== 'good-code') {
                    return ['status' => 400, 'json' => ['error' => 'invalid_grant']];
                }
                return ['json' => ['access_token' => 'at', 'id_token' => $this->idToken()]];
            },
        ]);
        $http = new HttpClient($this->fake, static fn(): array => ['93.184.216.34']);
        $this->cms->container->instance(HttpClient::class, $http);

        $this->idp = new TestIdp(['client_id' => 'client-1', 'client_secret' => 's3cret'], $http, new ArrayStore());
        $this->cms->auth->authenticator($this->idp);
    }

    /** @var array<string, string> */
    public array $lastTokenForm = [];

    private function idToken(): string
    {
        [$kid, $pem] = $this->tokenKid === 'k2' ? ['k2', $this->rotatedPem] : $this->signing;

        return Jwt::encode($this->claims + ['nonce' => $this->nonce] + [
            'iss' => 'https://idp.example', 'aud' => 'client-1', 'sub' => 'idp-user-1',
            'iat' => time(), 'exp' => time() + 300,
            'email' => 'new.person@example.test', 'email_verified' => true, 'name' => 'New Person',
        ], JwtKey::fromPem($pem, 'ES256', $kid));
    }

    private function service(): ExternalLogin
    {
        $this->nextRequest();
        return $this->cms->container->make(ExternalLogin::class);
    }

    /** Start, then come back from the provider. @return array{0: ExternalLoginResult, 1: array<string, string>} */
    private function roundTrip(string $intent = 'login', ?string $returnTo = null, string $code = 'good-code', ?callable $tamper = null): array
    {
        $start = $this->service()->begin('idp', $intent, $returnTo, '203.0.113.20');
        if ($start->status === ExternalLoginResult::FAILED) {
            return [$start, []];
        }
        parse_str((string) parse_url($start->redirect, PHP_URL_QUERY), $q);
        $this->nonce = $q['nonce'];
        if ($tamper !== null) {
            $tamper($q);
        }

        return [$this->callback(['state' => $q['state'], 'code' => $code]), $q];
    }

    /** @param array<string, string> $query */
    private function callback(array $query): ExternalLoginResult
    {
        $request = new Request('GET', '/auth/idp/callback', '/auth/idp/callback', [], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.20']);

        return $this->service()->finish('idp', $request);
    }

    private function open(): void
    {
        $this->cms->config->set('auth.registration', 'open');
    }

    public function testOidcSignInRegistersANewAccount(): void
    {
        $this->open();
        [$result, $q] = $this->roundTrip(returnTo: '/members?tab=1');

        $this->assertSame(ExternalLoginResult::SUCCESS, $result->status, $result->message);
        $this->assertTrue($result->registered);
        $this->assertSame('/members?tab=1', $result->redirect);

        // Authorization request: code flow, PKCE S256, nonce, exact redirect URI.
        $this->assertSame(['code', 'client-1', 'https://site.example/auth/idp/callback', 'S256', 'openid email profile'], [$q['response_type'], $q['client_id'], $q['redirect_uri'], $q['code_challenge_method'], $q['scope']]);
        $this->assertSame(43, strlen($q['state']));
        // Token request proves possession of the PKCE verifier.
        $this->assertSame($q['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $this->lastTokenForm['code_verifier'], true)), '+/', '-_'), '='));
        $this->assertSame('https://site.example/auth/idp/callback', $this->lastTokenForm['redirect_uri']);

        $user = $this->db->table('users')->where('email', 'new.person@example.test')->first();
        $this->assertSame(['new.person', 'user'], [$user['username'], $user['role']]);
        $this->assertTrue(str_starts_with($user['password'], '!'), 'no usable password');
        $this->assertSame((int) $user['id'], $this->nextRequest()->id());
        $this->assertSame('external:idp', $this->nextRequest()->method());
        $this->assertSame(1, $this->db->table('user_identities')->where('provider', 'idp')->where('subject', 'idp-user-1')->count());
        $events = $this->auditEvents();
        foreach (['auth.identity_linked', 'auth.registered', 'auth.login'] as $event) {
            $this->assertContains($event, $events);
        }
        $this->assertSame([], $this->mail, 'no "account connected" mail for a brand-new account');

        // Second sign-in finds the identity, even after the email changed at the provider.
        $this->nextRequest()->logout();
        $this->claims = ['email' => 'renamed@example.test'];
        [$again] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $again->status);
        $this->assertFalse($again->registered);
        $this->assertSame(1, $this->db->table('users')->count());
    }

    public function testStateIsSingleUseAndBoundToTheSession(): void
    {
        $this->open();
        [$first, $q] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $first->status);

        $this->assertSame(ExternalLoginResult::FAILED, $this->callback(['state' => $q['state'], 'code' => 'good-code'])->status, 'replayed callback');
        $this->assertSame(ExternalLoginResult::FAILED, $this->callback(['state' => 'forged', 'code' => 'good-code'])->status, 'unknown state');

        // Login CSRF: the attacker's callback URL opened in another browser.
        $start = $this->service()->begin('idp', 'login', null, '203.0.113.20');
        parse_str((string) parse_url($start->redirect, PHP_URL_QUERY), $q2);
        $_SESSION = [];
        $this->assertSame(ExternalLoginResult::FAILED, $this->callback(['state' => $q2['state'], 'code' => 'good-code'])->status, 'other session');

        // Expired attempts.
        $start = $this->service()->begin('idp', 'login', null, '203.0.113.20');
        parse_str((string) parse_url($start->redirect, PHP_URL_QUERY), $q3);
        foreach ($_SESSION['_auth_attempts'] as &$a) {
            $a['expiresAt'] = time() - 1;
        }
        unset($a);
        $this->assertSame(ExternalLoginResult::FAILED, $this->callback(['state' => $q3['state'], 'code' => 'good-code'])->status, 'expired');
    }

    public function testIdTokenIsVerified(): void
    {
        $this->open();
        $cases = [
            'wrong nonce' => ['nonce' => 'other'],
            'wrong audience' => ['aud' => 'someone-else'],
            'wrong issuer' => ['iss' => 'https://evil.example'],
            'expired' => ['exp' => time() - 3600],
            'azp mismatch' => ['aud' => ['client-1', 'other'], 'azp' => 'other'],
        ];
        foreach ($cases as $name => $claims) {
            $this->claims = $claims;
            [$result] = $this->roundTrip();
            $this->assertSame(ExternalLoginResult::FAILED, $result->status, $name);
            $this->assertSame('Sign-in failed. Please try again.', $result->message, "{$name}: generic message, details only logged");
        }
        $this->claims = [];
        [$denied] = $this->roundTrip(code: 'bad-code');
        $this->assertSame(ExternalLoginResult::FAILED, $denied->status, 'token endpoint error');
        $this->assertSame(0, $this->db->table('users')->count());
        $this->assertNull($this->nextRequest()->id());

        // Signed by a key the provider never published.
        $foreign = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($foreign, $foreignPem);
        $this->signing = ['k1', $foreignPem];
        [$forged] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $forged->status, 'bad signature');

        // Cancelled at the provider.
        $start = $this->service()->begin('idp', 'login', null, '203.0.113.20');
        parse_str((string) parse_url($start->redirect, PHP_URL_QUERY), $q);
        $cancel = $this->callback(['state' => $q['state'], 'error' => 'access_denied']);
        $this->assertSame('Sign-in was cancelled.', $cancel->message);
    }

    public function testProviderKeyRotationIsPickedUp(): void
    {
        $this->open();
        [$first] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $first->status);
        $this->nextRequest()->logout();

        $this->tokenKid = 'k2'; // tokens now signed with a key not in our cached JWKS
        [$second] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $second->status, 'JWKS refetched for the unknown kid');
    }

    public function testExistingEmailIsOnlyLinkedWhenVerifiedAndAllowed(): void
    {
        $owner = $this->createUser(['email' => 'new.person@example.test', 'username' => 'owner']);

        [$refused] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $refused->status, 'auto-link is off by default');
        $this->assertContains('already exists', $refused->message);

        $this->cms->config->set('auth.link_by_verified_email', true);
        $this->claims = ['email_verified' => false];
        [$unverified] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $unverified->status, 'unverified email never links');

        $this->claims = [];
        $this->idp->trusts = false;
        [$untrusted] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $untrusted->status, 'provider that does not verify emails');

        $this->idp->trusts = true;
        $this->claims = [];
        [$linked] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $linked->status, $linked->message);
        $this->assertSame((int) $owner['id'], $this->nextRequest()->id());
        $this->assertCount(1, $this->mail, 'owner told about the new sign-in method');
        $this->assertSame('new.person@example.test', $this->mail[0]['to']);
    }

    public function testRegistrationPolicies(): void
    {
        [$closed] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $closed->status);
        $this->assertContains('registration is closed', $closed->message);

        $this->cms->config->set('auth.registration', 'domains');
        $this->cms->config->set('auth.registration_domains', 'corp.example, example.org');
        [$outside] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $outside->status, 'domain not allowed');

        $this->claims = ['email' => 'staff@corp.example', 'email_verified' => false];
        [$unverified] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $unverified->status, 'domain allowlist needs a verified email');

        $this->claims = ['email' => 'staff@corp.example'];
        $this->cms->config->set('auth.default_role', 'admin');
        [$ok] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $ok->status);
        $this->assertSame('user', $this->db->table('users')->where('email', 'staff@corp.example')->value('role'), 'self-registration never grants admin');

        $this->cms->filters->add('auth.can_register', static fn(bool $allowed) => false);
        $this->nextRequest()->logout();
        $this->claims = ['email' => 'second@corp.example', 'sub' => 'idp-user-2'];
        [$filtered] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::FAILED, $filtered->status, 'auth.can_register filter');
    }

    public function testSecondFactorStillApplies(): void
    {
        $this->open();
        [$first] = $this->roundTrip();
        $userId = (int) $first->user['id'];
        $this->nextRequest()->logout();

        $tf = $this->cms->container->make(TwoFactor::class);
        $enroll = $tf->beginTotp($userId, 'x', 'Test');
        $tf->confirmTotp($userId, Totp::code($enroll['secret'], Totp::step()));

        [$second] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::TWO_FACTOR, $second->status);
        $this->assertNull($this->nextRequest()->id(), 'not signed in yet');
        $this->assertSame('external:idp', $this->nextRequest()->pendingSecondFactor()['method']);

        $this->cms->config->set('auth.trust_provider_mfa', true);
        $this->claims['amr'] = ['pwd', 'mfa'];
        [$trusted] = $this->roundTrip();
        $this->assertSame(ExternalLoginResult::SUCCESS, $trusted->status, 'provider MFA trusted when enabled');
        $this->assertSame('external:idp+provider-mfa', $this->nextRequest()->method());
    }

    public function testLinkingFromTheProfile(): void
    {
        $ana = $this->createUser(['email' => 'ana@example.test']);
        $this->nextRequest()->login($ana);

        [$linked] = $this->roundTrip('link', '/profile');
        $this->assertSame(ExternalLoginResult::LINKED, $linked->status, $linked->message);
        $this->assertSame('/profile', $linked->redirect);
        $this->assertSame((int) $ana['id'], (int) $this->db->table('user_identities')->value('user_id'));
        $this->assertCount(1, $this->mail);

        // Old sign-in: confirm first.
        $_SESSION['_auth_at'] = time() - 3600;
        $stale = $this->service()->begin('idp', 'link', '/profile', '203.0.113.20');
        $this->assertSame(ExternalLoginResult::FAILED, $stale->status);

        // Someone else's identity can't be taken over by linking.
        $bob = $this->createUser(['email' => 'bob@example.test', 'username' => 'bob']);
        $this->nextRequest()->login($bob);
        [$taken] = $this->roundTrip('link', '/profile');
        $this->assertSame(ExternalLoginResult::FAILED, $taken->status);
        $this->assertContains('another user', $taken->message);

        // Guests can't link.
        $this->nextRequest()->logout();
        $this->assertSame(ExternalLoginResult::FAILED, $this->service()->begin('idp', 'link', null, '203.0.113.20')->status);
    }

    public function testIdentityApi(): void
    {
        $this->open();
        [$first] = $this->roundTrip();
        $userId = (int) $first->user['id'];

        $providers = $this->json($this->api('GET', '/auth/providers'));
        $this->assertSame([['id' => 'idp', 'label' => 'Continue with TestIdP', 'icon' => null, 'login_url' => '/auth/idp/redirect']], $providers['data']);

        $list = $this->json($this->api('GET', '/me/identities'));
        $this->assertSame('idp', $list['data'][0]['provider']);
        $this->assertFalse($list['meta']['has_password']);
        $id = $list['data'][0]['id'];

        $only = $this->api('DELETE', "/me/identities/{$id}");
        $this->assertSame(409, $only->statusCode(), 'last way to sign in');

        $this->db->table('users')->where('id', $userId)->update(['password' => \Kestello\Support\Hash::password(self::PASSWORD)]);
        $this->nextRequest()->refreshPassword((array) $this->db->table('users')->where('id', $userId)->first());
        $this->assertSame(204, $this->api('DELETE', "/me/identities/{$id}")->statusCode());
        $this->assertSame(0, $this->db->table('user_identities')->count());
        $this->assertContains('auth.identity_unlinked', $this->auditEvents());
        $this->assertSame(404, $this->api('DELETE', "/me/identities/{$id}")->statusCode());
    }

    public function testRegistryRules(): void
    {
        $plain = new class (['client_id' => 'a', 'client_secret' => 'b']) extends OAuth2Authenticator {
            public string $name = 'acme';
            public function id(): string
            {
                return $this->name;
            }
            public function label(): string
            {
                return 'Acme';
            }
            protected function authorizationEndpoint(): string
            {
                return 'https://acme.example/auth';
            }
            protected function tokenEndpoint(): string
            {
                return 'https://acme.example/token';
            }
            protected function mapUser(array $tokens, AuthAttempt $attempt): ExternalIdentity
            {
                return new ExternalIdentity('acme', '1');
            }
        };

        $this->cms->auth->setPermissionResolver(static fn(string $owner): ?array => match ($owner) {
            'plugin:allowed' => ['auth' => ['authenticator']],
            'plugin:nope' => ['network' => ['acme.example']],
            default => null,
        });
        $e = $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:nope', fn() => $this->cms->auth->authenticator($plain)));
        $this->assertContains('permissions', $e->getMessage());
        ExecutionContext::run('plugin:allowed', fn() => $this->cms->auth->authenticator($plain));
        $this->assertNotNull($this->cms->auth->findAuthenticator('acme'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->auth->authenticator($plain), 'duplicate id');

        $plain->name = 'password';
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->auth->authenticator($plain), 'reserved id');
        $plain->name = 'Bad Id';
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->auth->authenticator($plain), 'invalid id');

        $unconfigured = new TestIdp([]);
        $copy = new class ([]) extends OidcAuthenticator {
            public function id(): string
            {
                return 'unset';
            }
            public function label(): string
            {
                return 'Unset';
            }
            protected function issuer(): string
            {
                return 'https://unset.example';
            }
        };
        $this->cms->auth->authenticator($copy);
        $this->assertNull($this->cms->auth->findAuthenticator('unset'), 'no credentials → hidden');
        $this->assertSame(ExternalLoginResult::FAILED, $this->service()->begin('unset', 'login', null, '1.1.1.1')->status);
        unset($unconfigured);
    }

    public function testReturnPathsStayOnSite(): void
    {
        foreach (['/', '/admin', '/a?b=c#d', '/p/ü'] as $ok) {
            $this->assertSame($ok, ReturnPath::sanitize($ok), $ok);
        }
        foreach (['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', "/a\nb", '', 'admin', "/\x00", '/a\\b', null, ['x']] as $bad) {
            $this->assertNull(ReturnPath::sanitize($bad), var_export($bad, true));
        }

        $this->open();
        $this->cms->filters->add('auth.redirect_after_login', static fn() => 'https://evil.example/');
        [$result] = $this->roundTrip(returnTo: '//evil.example');
        $this->assertSame('/', $result->redirect, 'bad return_to dropped, filtered value re-validated');
    }

    public function testCallbackRateLimit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->callback(['state' => 'x' . $i]);
        }
        $this->assertContains('Too many', $this->callback(['state' => 'x'])->message);
    }
}
