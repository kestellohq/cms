<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\LoginResult;
use Kestello\Cms\Auth\LoginService;
use Kestello\Cms\Auth\LoginThrottle;
use Kestello\Cms\Auth\Totp;
use Kestello\Cms\Auth\TwoFactor;
use Kestello\Cms\Events\LoginFailed;
use Kestello\Cms\Events\UserLoggedIn;
use Kestello\Framework\Core\Request;
use Kestello\Support\Clock;

final class LoginTest extends AuthTestCase
{
    /**
     * The throttles count in fixed windows that start on the clock (a minute,
     * an hour). A test run across 15:00 split its attempts over two windows and
     * never reached the limit, so the clock stands still, well inside one.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2027-01-01 12:00:10');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function login(): LoginService
    {
        return $this->cms->container->make(LoginService::class);
    }

    public function testPasswordLoginByEmailOrUsername(): void
    {
        $user = $this->createUser();
        $seen = [];
        $this->cms->events->on(UserLoggedIn::class, function (UserLoggedIn $e) use (&$seen) {
            $seen[] = $e;
        });

        $result = $this->login()->attempt('  ANA@Example.TEST ', self::PASSWORD, '203.0.113.1');
        $this->assertTrue($result->ok(), 'email is case-insensitive');
        $this->assertSame(['id' => (int) $user['id'], 'username' => 'ana', 'email' => 'ana@example.test', 'role' => 'admin'], $result->user);
        $this->assertSame((int) $user['id'], $this->nextRequest()->id());
        $this->assertSame('password', $this->nextRequest()->method());
        $this->assertCount(1, $seen);
        $this->assertTrue(!isset($seen[0]->user['password']), 'no hash in events');

        $this->assertTrue($this->login()->attempt('ana', self::PASSWORD, '203.0.113.1')->ok(), 'username works');
    }

    public function testFailuresAreGenericAndAudited(): void
    {
        $this->createUser();
        $this->createUser(['username' => 'off', 'email' => 'off@example.test', 'is_active' => 0]);
        $failed = [];
        $this->cms->events->on(LoginFailed::class, function (LoginFailed $e) use (&$failed) {
            $failed[] = $e->reason;
        });

        foreach ([['ana', 'wrong password'], ['nobody@example.test', self::PASSWORD], ['off', self::PASSWORD], ['', 'x']] as [$who, $password]) {
            $result = $this->login()->attempt($who, $password, '203.0.113.2');
            $this->assertSame(LoginResult::FAILED, $result->status, $who);
            $this->assertNull($result->user, $who);
            $this->assertNull($this->nextRequest()->user(), "{$who}: nobody signed in");
        }

        $this->assertSame(['credentials', 'credentials', 'credentials', 'credentials'], $failed);
        $rows = $this->cms->audit->query(['event' => 'auth.login_failed']);
        $this->assertCount(4, $rows);
        $unknown = array_values(array_filter($rows, static fn($r) => $r['subject_id'] === null));
        $this->assertTrue(isset($unknown[0]['data']['identifier_hash']), 'unknown accounts are recorded by hash only');
        $this->assertFalse(str_contains(json_encode($rows), 'nobody@example.test'), 'raw identifiers never stored');
    }

    public function testOldHashesAreUpgradedOnLogin(): void
    {
        $user = $this->createUser(['password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);

        $this->assertTrue($this->login()->attempt('ana', self::PASSWORD, '203.0.113.3')->ok());
        $hash = (string) $this->db->table('users')->where('id', $user['id'])->value('password');
        $this->assertTrue(str_starts_with($hash, '$argon2id$'), $hash);
        $this->assertTrue($this->login()->attempt('ana', self::PASSWORD, '203.0.113.3')->ok(), 'still verifies');
    }

    public function testThrottlingPerIpAndPerAccount(): void
    {
        $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(LoginResult::FAILED, $this->login()->attempt('ana', 'nope', '203.0.113.4')->status);
        }
        $blocked = $this->login()->attempt('ana', self::PASSWORD, '203.0.113.4');
        $this->assertSame(LoginResult::THROTTLED, $blocked->status, 'IP blocked even with the right password');
        $this->assertTrue($blocked->retryAfter > 0 && $blocked->retryAfter <= 60);

        // Failures from one address don't lock the owner out elsewhere...
        $this->assertTrue($this->login()->attempt('ana', self::PASSWORD, '192.0.2.150')->ok(), 'the owner signs in from their own address');
        // ...but guesses spread over many addresses meet the account's own limit (100/hour).
        for ($i = 0; $i < 100; $i++) {
            $this->login()->attempt('ana', 'nope', '198.51.100.' . intdiv($i, 4));
        }
        $this->assertSame(LoginResult::THROTTLED, $this->login()->attempt('ana', self::PASSWORD, '192.0.2.200')->status, 'account blocked');
        $this->assertSame(LoginResult::FAILED, $this->login()->attempt('someone-else', 'x', '192.0.2.201')->status, 'other accounts unaffected');
    }

    public function testSuccessClearsTheAccountCounter(): void
    {
        $this->createUser();
        for ($i = 0; $i < 9; $i++) {
            $this->login()->attempt('ana', 'nope', '198.51.100.' . $i);
        }
        $this->assertTrue($this->login()->attempt('ana', self::PASSWORD, '192.0.2.1')->ok());
        $this->login()->attempt('ana', 'nope', '192.0.2.2');
        $this->assertTrue($this->login()->attempt('ana', self::PASSWORD, '192.0.2.3')->ok(), 'counter restarted');
    }

    public function testTwoFactorLogin(): void
    {
        $user = $this->createUser();
        $uid = (int) $user['id'];
        $tf = $this->cms->container->make(TwoFactor::class);

        $enroll = $tf->beginTotp($uid, 'ana@example.test', 'Kestello Test');
        $this->assertTrue(str_starts_with($enroll['otpauth_uri'], 'otpauth://totp/Kestello%20Test:ana%40example.test?secret=' . $enroll['secret']));
        $this->assertFalse($tf->isEnabled($uid), 'not active until confirmed');
        $this->assertFalse(str_contains((string) $this->db->table('mfa_methods')->value('secret'), $enroll['secret']), 'secret sealed at rest');
        $this->assertNull($tf->confirmTotp($uid, '000000'));
        $codes = $tf->confirmTotp($uid, Totp::code($enroll['secret'], Totp::step()));
        $this->assertCount(10, (array) $codes);
        $this->assertTrue(preg_match('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/', $codes[0]) === 1);
        $this->assertThrows(\LogicException::class, fn() => $tf->beginTotp($uid, 'a', 'b'));

        // Password alone is not enough.
        $this->assertSame(LoginResult::TWO_FACTOR, $this->login()->attempt('ana', self::PASSWORD, '203.0.113.5')->status);
        $this->assertNull($this->nextRequest()->user(), 'nobody signed in yet');
        $this->assertTrue($this->login()->hasPendingChallenge());

        // The enrollment code was used at the current step: it can't be replayed.
        $this->assertSame(LoginResult::FAILED, $this->login()->verifySecondFactor(Totp::code($enroll['secret'], Totp::step()), '203.0.113.5')->status, 'replay refused');
        $next = $this->login()->verifySecondFactor(Totp::code($enroll['secret'], Totp::step() + 1), '203.0.113.5');
        $this->assertTrue($next->ok());
        $this->assertSame('password+totp', $next->method);
        $this->assertSame($uid, $this->nextRequest()->id());

        // A password change while the challenge waits ends it.
        $this->login()->attempt('ana', self::PASSWORD, '203.0.113.5');
        $this->db->table('users')->where('id', $uid)->update(['password' => \Kestello\Support\Hash::password('a-new-long-password-1')]);
        $this->assertSame(LoginResult::EXPIRED, $this->login()->verifySecondFactor($codes[4], '203.0.113.5')->status, 'challenge from the old password');
        $this->db->table('users')->where('id', $uid)->update(['password' => \Kestello\Support\Hash::password(self::PASSWORD)]);

        // Recovery codes: any case, with or without the dash, once.
        $this->login()->attempt('ana', self::PASSWORD, '203.0.113.5');
        $result = $this->login()->verifySecondFactor(strtolower(str_replace('-', '', $codes[3])), '203.0.113.5');
        $this->assertTrue($result->ok());
        $this->assertSame(['password+recovery', 9], [$result->method, $result->recoveryCodesLeft]);
        $this->login()->attempt('ana', self::PASSWORD, '203.0.113.5');
        $this->assertSame(LoginResult::FAILED, $this->login()->verifySecondFactor($codes[3], '203.0.113.5')->status, 'used up');

        // Too many wrong codes: start over with the password.
        for ($i = 1; $i < LoginService::MAX_CODE_ATTEMPTS - 1; $i++) {
            $this->assertSame(LoginResult::FAILED, $this->login()->verifySecondFactor('000000', '198.51.100.' . $i)->status);
        }
        $this->assertSame(LoginResult::EXPIRED, $this->login()->verifySecondFactor('000000', '198.51.100.99')->status);
        $this->assertFalse($this->login()->hasPendingChallenge());
        $this->assertSame(LoginResult::EXPIRED, $this->login()->verifySecondFactor($codes[5], '203.0.113.5')->status);

        $this->assertContains('auth.login_failed', $this->auditEvents());
        $this->assertTrue($tf->disable($uid));
        $this->assertSame(0, $this->db->table('mfa_recovery_codes')->count());
    }

    public function testGuessingTheSecondFactorIsCappedAndTheOwnerIsWarned(): void
    {
        $user = $this->createUser();
        $uid = (int) $user['id'];
        $tf = $this->cms->container->make(TwoFactor::class);
        $enroll = $tf->beginTotp($uid, 'ana@example.test', 'Kestello Test');
        $tf->confirmTotp($uid, Totp::code($enroll['secret'], Totp::step()));

        // someone with the password, spread over many addresses, one wrong code per challenge
        $statuses = [];
        for ($i = 1; $i <= 25; $i++) {
            $ip = '198.51.100.' . $i;
            $this->assertSame(LoginResult::TWO_FACTOR, $this->login()->attempt('ana', self::PASSWORD, $ip)->status);
            $statuses[] = $this->login()->verifySecondFactor('000000', $ip)->status;
        }
        $capped = LoginThrottle::SCOPE_LIMITS['2fa'];
        $this->assertSame(array_fill(0, $capped, LoginResult::FAILED), array_slice($statuses, 0, $capped));
        $this->assertSame(array_fill(0, 25 - $capped, LoginResult::THROTTLED), array_slice($statuses, $capped), 'the account stops taking codes for the hour');

        $warnings = array_values(array_filter($this->mail, static fn(array $m): bool => str_contains($m['subject'], 'has your')));
        $this->assertCount(1, $warnings, 'the owner is told once');
        $this->assertSame('ana@example.test', $warnings[0]['to']);
        $this->assertStringContainsString('change your password', $warnings[0]['html']);
    }

    public function testPendingChallengeExpires(): void
    {
        $this->createUser();
        $tf = $this->cms->container->make(TwoFactor::class);
        $secret = $tf->beginTotp(1, 'a', 'b')['secret'];
        $tf->confirmTotp(1, Totp::code($secret, Totp::step()));

        $this->login()->attempt('ana', self::PASSWORD, '203.0.113.6');
        $_SESSION['_auth_pending']['expires'] = time() - 1;
        $this->assertSame(LoginResult::EXPIRED, $this->login()->verifySecondFactor(Totp::code($secret, Totp::step() + 1), '203.0.113.6')->status);
    }

    public function testSealedSecretsAreBoundToTheirUser(): void
    {
        $this->createUser();
        $this->createUser(['username' => 'bob', 'email' => 'bob@example.test']);
        $tf = $this->cms->container->make(TwoFactor::class);
        $tf->beginTotp(1, 'a', 'b');

        // Copy ana's sealed secret onto bob: it must not open.
        $sealed = (string) $this->db->table('mfa_methods')->where('user_id', 1)->value('secret');
        $this->db->table('mfa_methods')->insert(['user_id' => 2, 'type' => 'totp', 'secret' => $sealed, 'created_at' => time(), 'confirmed_at' => time()]);
        $this->assertThrows(\Throwable::class, fn() => $tf->verify(2, '123456'));
    }

    public function testTotpMatchesRfc6238(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        $this->assertSame(Totp::step(1234567890), Totp::verify($secret, '005 924', time: 1234567890));
        $this->assertNull(Totp::verify($secret, '005924', lastUsedStep: Totp::step(1234567890), time: 1234567890), 'replay');
        $this->assertNull(Totp::verify($secret, '12345', time: 1234567890));
        $this->assertSame('12345678901234567890', Totp::base32Decode(strtolower($secret)));
    }

    public function testTheSignInFormAsksForWhatIsMissingWithoutTryingToSignIn(): void
    {
        $this->createUser();
        $failed = 0;
        $this->cms->events->on(LoginFailed::class, function () use (&$failed) {
            $failed++;
        });
        $post = function (array $body): array {
            $this->nextRequest();
            $request = new Request('POST', '/login', '/login', [], [], $body, [], [], ['REMOTE_ADDR' => '203.0.113.7'], '');
            $_SESSION['_flash']['new'] = [];
            $response = $this->cms->routes->dispatch($request);
            $next = $_SESSION['_flash']['new'];   // what the sign-in page shows on the next request

            return [$response->statusCode(), $response->headers()['Location'] ?? null, $next['_errors'] ?? null, $next['_old_input'] ?? null];
        };

        $this->assertSame(
            [302, '/login', ['email' => ['Enter your email address or username.'], 'password' => ['Enter your password.']], ['email' => '']],
            $post(['email' => '  ', 'password' => '']),
        );
        $this->assertSame(
            [302, '/login', ['password' => ['Enter your password.']], ['email' => 'ana']],
            $post(['email' => ' ana ', 'password' => '']),
        );
        $this->assertSame(0, $failed, 'nothing was tried, so nothing failed or counted towards the throttle');
    }
}
