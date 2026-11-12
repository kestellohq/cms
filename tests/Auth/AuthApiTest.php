<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\Totp;
use Kestello\Framework\Core\ExecutionContext;

final class AuthApiTest extends AuthTestCase
{
    public function testSessionLoginAndLogout(): void
    {
        $this->createUser();

        $bad = $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => 'nope']);
        $this->assertSame(401, $bad->statusCode());
        $this->assertSame('urn:kestello:problem:invalid_credentials', $this->json($bad)['type']);
        $unknown = $this->api('POST', '/auth/session', ['login' => 'zed', 'password' => 'nope']);
        $this->assertSame($this->json($bad)['detail'], $this->json($unknown)['detail'], 'same message for unknown accounts');

        $ok = $this->api('POST', '/auth/session', ['login' => 'ana@example.test', 'password' => self::PASSWORD]);
        $this->assertSame(200, $ok->statusCode());
        $this->assertFalse($this->json($ok)['data']['two_factor_required']);
        $this->assertSame('ana', $this->json($ok)['data']['user']['username']);

        $me = $this->json($this->api('GET', '/me'));
        $this->assertSame(['enabled' => false, 'expected' => true], $me['data']['auth']['two_factor']);

        $this->assertSame(204, $this->api('DELETE', '/auth/session')->statusCode());
        $this->assertSame(401, $this->api('GET', '/me')->statusCode());
        $this->assertSame(['auth.login_failed', 'auth.login_failed', 'auth.login', 'auth.logout'], $this->auditEvents());
    }

    public function testLoginNeedsCsrfAndIsThrottled(): void
    {
        $this->createUser();
        \Kestello\Support\Clock::freeze('2027-01-01 12:00:10');   // well inside one rate-limit window
        $this->csrfValid = false;
        $this->assertSame(403, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode(), 'login CSRF');
        $this->csrfValid = true;

        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => 'nope']);
        }
        $limited = $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);
        $this->assertSame(429, $limited->statusCode());
        $this->assertTrue((int) ($limited->headers()['Retry-After'] ?? 0) > 0);
        \Kestello\Support\Clock::unfreeze();
    }

    public function testTwoFactorEnrollmentOverTheApi(): void
    {
        $this->createUser();
        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);

        $begin = $this->api('POST', '/me/2fa/totp');
        $this->assertSame(200, $begin->statusCode());
        $secret = $this->json($begin)['data']['secret'];

        $this->assertSame(422, $this->api('POST', '/me/2fa/totp/confirm', ['code' => '000000'])->statusCode());
        $confirm = $this->api('POST', '/me/2fa/totp/confirm', ['code' => Totp::code($secret, Totp::step())]);
        $this->assertCount(10, $this->json($confirm)['data']['recovery_codes']);
        $this->assertSame(409, $this->api('POST', '/me/2fa/totp')->statusCode(), 'already enabled');
        $this->assertTrue($this->json($this->api('GET', '/me/2fa'))['data']['enabled']);

        // Sign in again: two steps now.
        $this->api('DELETE', '/auth/session');
        $step1 = $this->json($this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]));
        $this->assertTrue($step1['data']['two_factor_required']);
        $this->assertSame(401, $this->api('GET', '/me')->statusCode(), 'not signed in between the steps');
        $this->assertSame(401, $this->api('POST', '/auth/2fa/verify', ['code' => '000000'])->statusCode());
        $step2 = $this->api('POST', '/auth/2fa/verify', ['code' => Totp::code($secret, Totp::step() + 1)]);
        $this->assertSame(200, $step2->statusCode());
        $this->assertSame('ana', $this->json($step2)['data']['user']['username']);

        // Sensitive changes need a recent password: make the login 11 minutes old.
        $_SESSION['_auth_at'] = time() - 660;
        $this->assertSame(401, $this->api('DELETE', '/me/2fa')->statusCode());
        $this->assertSame(401, $this->api('POST', '/auth/confirm', ['password' => 'nope'])->statusCode());
        $this->assertSame(200, $this->api('POST', '/auth/confirm', ['password' => self::PASSWORD])->statusCode());
        $this->assertSame(10, count($this->json($this->api('POST', '/me/2fa/recovery-codes'))['data']['recovery_codes']));
        $this->assertSame(204, $this->api('DELETE', '/me/2fa')->statusCode());
        $this->assertFalse($this->json($this->api('GET', '/me/2fa'))['data']['enabled']);

        $events = $this->auditEvents();
        $this->assertContains('auth.2fa_enabled', $events);
        $this->assertContains('auth.2fa_disabled', $events);
    }

    public function testChangePassword(): void
    {
        $this->createUser();
        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);

        $wrong = $this->api('PUT', '/me/password', ['current_password' => 'nope', 'password' => 'a brand new passphrase']);
        $this->assertSame(['current_password'], array_keys($this->json($wrong)['errors']));
        $weak = $this->api('PUT', '/me/password', ['current_password' => self::PASSWORD, 'password' => 'short']);
        $this->assertSame(['password'], array_keys($this->json($weak)['errors']));

        $ok = $this->api('PUT', '/me/password', ['current_password' => self::PASSWORD, 'password' => 'a brand new passphrase']);
        $this->assertSame(200, $ok->statusCode());
        $this->assertSame(200, $this->api('GET', '/me')->statusCode(), 'this session survives');
    }

    public function testAccountEndpointsRefuseTokens(): void
    {
        $user = $this->createUser();
        $token = (new \Kestello\Cms\Auth\PersonalAccessTokens())->create((int) $user['id'], 'ci', ['admin'])['token'];

        $this->nextRequest();
        $request = new \Kestello\Framework\Core\Request('PUT', '/api/v1/me/password', '/api/v1/me/password', ['Authorization' => 'Bearer ' . $token], [], ['current_password' => self::PASSWORD, 'password' => 'a brand new passphrase'], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame(403, $this->cms->routes->dispatch($request)->statusCode(), 'even an admin-scoped token cannot change the password');
    }

    public function testAuditLog(): void
    {
        $audit = $this->cms->audit;
        $audit->record('auth.custom', ['user', 1], ['password' => 'hunter2', 'nested' => ['api_token' => 'x', 'ok' => 1], 'confirmed' => true, 'tokens_revoked' => 2]);
        $row = $audit->query(['event' => 'auth.custom'])[0];
        $this->assertSame(['password' => '[redacted]', 'nested' => ['api_token' => '[redacted]', 'ok' => 1], 'confirmed' => true, 'tokens_revoked' => 2], $row['data']);
        $this->assertSame('core', $row['owner']);

        ExecutionContext::run('plugin:acme-shop', fn() => $audit->record('acme-shop.refund', ['order', 9]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme-shop', fn() => $audit->record('auth.login')));
        $this->assertSame('plugin:acme-shop', $audit->query(['event' => 'acme-shop.*'])[0]['owner']);
        $this->assertCount(2, $audit->query());
        $this->assertCount(1, $audit->query(['subject_type' => 'order', 'subject_id' => '9']));

        // Over HTTP: admins only.
        $this->createUser();
        $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->api('POST', '/auth/session', ['login' => 'bob', 'password' => self::PASSWORD]);
        $this->assertSame(403, $this->api('GET', '/audit')->statusCode());
        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);
        $page = $this->json($this->api('GET', '/audit?event=auth.*&limit=2'));
        $this->assertCount(2, $page['data']);
        $this->assertNotNull($page['meta']['next_before_id']);
        $older = $this->json($this->api('GET', '/audit?event=auth.*&before_id=' . $page['meta']['next_before_id']));
        $this->assertTrue(min(array_column($page['data'], 'id')) > max(array_column($older['data'], 'id')));

        $this->db->table('audit_log')->update(['occurred_at' => time() - 400 * 86400]);
        $this->assertTrue($audit->prune(365) >= 4);
    }
}
