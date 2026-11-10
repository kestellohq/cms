<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Auth\LoginService;
use Kestello\Cms\Auth\PasswordPolicy;
use Kestello\Cms\Auth\PasswordResets;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Framework\Session\SessionMetadata;
use Kestello\Support\Hash;

final class PasswordTest extends AuthTestCase
{
    private function resets(): PasswordResets
    {
        return $this->cms->container->make(PasswordResets::class);
    }

    private function tokenFromMail(): string
    {
        preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', end($this->mail)['html'], $m);

        return $m[1] ?? '';
    }

    public function testResetRequestNeverRevealsAccounts(): void
    {
        $this->createUser();
        $this->createUser(['username' => 'off', 'email' => 'off@example.test', 'is_active' => 0]);

        $this->resets()->request('nobody@example.test', '203.0.113.1');
        $this->resets()->request('off@example.test', '203.0.113.1');
        $this->resets()->request('not an email', '203.0.113.1');
        $this->assertCount(0, $this->mail);

        $this->resets()->request(' Ana@EXAMPLE.test', '203.0.113.1');
        $this->assertCount(1, $this->mail);
        $this->assertSame('ana@example.test', $this->mail[0]['to']);
        $this->assertSame(43, strlen($this->tokenFromMail()));
        $this->assertFalse(str_contains((string) $this->db->table('password_resets')->value('token_hash'), $this->tokenFromMail()), 'only the hash is stored');

        // Both API answers look the same.
        $known = $this->api('POST', '/auth/password/forgot', ['email' => 'ana@example.test']);
        $unknown = $this->api('POST', '/auth/password/forgot', ['email' => 'zed@example.test']);
        $this->assertSame([202, 202], [$known->statusCode(), $unknown->statusCode()]);
        $this->assertSame($this->json($known), $this->json($unknown));
    }

    public function testResetIsRateLimitedPerAddress(): void
    {
        $this->createUser();
        for ($i = 0; $i < 5; $i++) {
            $this->resets()->request('ana@example.test', '198.51.100.7');
        }
        $this->assertCount(3, $this->mail, '3 an hour for an address from one place');
        $this->resets()->request('ana@example.test', '203.0.113.50');
        $this->assertCount(4, $this->mail, 'someone else asking for it does not silence the owner');
        for ($i = 0; $i < 30; $i++) {
            $this->resets()->request('ana@example.test', '192.0.2.' . $i);
        }
        $this->assertCount(10, $this->mail, 'and at most 10 an hour reach a mailbox in all');
    }

    public function testResetFlow(): void
    {
        $user = $this->createUser();
        $uid = (int) $user['id'];

        // Ana has two signed-in devices and a token.
        foreach ([str_repeat('a', 48), str_repeat('b', 48)] as $sid) {
            $this->sessionUser = $uid;
            $this->sessions->write($sid, 'x|i:1;');
        }
        (new PersonalAccessTokens())->create($uid, 'ci', ['profile']);

        $this->resets()->request('ana@example.test', '203.0.113.1');
        $first = $this->tokenFromMail();
        $this->resets()->request('ana@example.test', '203.0.113.1');
        $token = $this->tokenFromMail();
        $this->assertNull($this->resets()->find($first), 'a new link replaces the old one');
        $this->assertSame($uid, (int) $this->resets()->find($token)['id']);

        $this->assertSame(['password'], array_keys($this->resets()->reset($token, 'short', '203.0.113.1')));
        $this->assertSame(['password'], array_keys($this->resets()->reset($token, 'password1234', '203.0.113.1')), 'common');
        $this->assertSame([], $this->resets()->reset($token, 'a brand new passphrase', '203.0.113.1'));
        $this->assertSame(['token'], array_keys($this->resets()->reset($token, 'another new passphrase', '203.0.113.1')), 'single use');

        $login = $this->cms->container->make(LoginService::class);
        $this->assertFalse($login->attempt('ana', self::PASSWORD, '192.0.2.1')->ok());
        $this->assertTrue($login->attempt('ana', 'a brand new passphrase', '192.0.2.1')->ok());

        $this->assertSame([], $this->sessions->forUser($uid), 'devices signed out');
        $this->assertSame([], (new PersonalAccessTokens())->forUser($uid), 'tokens revoked');
        $events = $this->auditEvents();
        $this->assertContains('auth.password_reset_requested', $events);
        $this->assertContains('auth.password_changed', $events);
        $this->assertContains('auth.token_revoked', $events);
    }

    public function testExpiredAndMalformedTokens(): void
    {
        $this->createUser();
        $this->resets()->request('ana@example.test', '203.0.113.1');
        $token = $this->tokenFromMail();
        $this->db->table('password_resets')->update(['expires_at' => time() - 1]);

        $this->assertNull($this->resets()->find($token));
        $this->assertSame(['token'], array_keys($this->resets()->reset($token, 'a brand new passphrase', '203.0.113.1')));
        $this->assertNull($this->resets()->find('../../etc/passwd'));
        $this->assertSame(1, $this->resets()->prune());

        $response = $this->api('POST', '/auth/password/reset', ['token' => $token, 'password' => 'a brand new passphrase']);
        $this->assertSame(422, $response->statusCode());
        $this->assertTrue(isset($this->json($response)['errors']['token']));
    }

    public function testPasswordChangeEndsOtherSessionsOnAnyStore(): void
    {
        $user = $this->createUser();
        $login = $this->cms->container->make(LoginService::class);
        $this->assertTrue($login->attempt('ana', self::PASSWORD, '203.0.113.1')->ok());
        $stolen = $_SESSION; // another browser holding the same login

        $accounts = $this->cms->container->make(AccountSecurity::class);
        $accounts->changePassword((int) $user['id'], 'a brand new passphrase', 'admin');

        $_SESSION = $stolen;
        $this->assertNull($this->nextRequest()->user(), 'the old session no longer works');

        // Only login() signs a session in, and it always records the password fingerprint.
        $_SESSION = $stolen;
        unset($_SESSION['_auth_pw']);
        $this->assertNull($this->nextRequest()->user(), 'a session without the fingerprint signs nobody in');

        // The user's own session survives their change.
        $this->assertTrue($login->attempt('ana', 'a brand new passphrase', '203.0.113.1')->ok());
        $accounts->changePassword((int) $user['id'], 'yet another passphrase', 'profile');
        $this->nextRequest()->refreshPassword((array) $this->db->table('users')->where('id', $user['id'])->first());
        $this->assertSame((int) $user['id'], $this->nextRequest()->id());
    }

    public function testDeviceSessions(): void
    {
        $uid = (int) $this->createUser()['id'];
        $other = (int) $this->createUser(['username' => 'bob', 'email' => 'bob@example.test'])['id'];
        $this->sessionUser = $uid;
        foreach (['laptop', 'phone', 'tablet'] as $device) {
            $this->sessions->write(str_pad($device, 48, 'x'), 'x|i:1;');
        }
        $this->sessionUser = $other;
        $this->sessions->write(str_pad('bob', 48, 'x'), 'x|i:1;');

        $accounts = $this->cms->container->make(AccountSecurity::class);
        $current = SessionMetadata::hashId(str_pad('laptop', 48, 'x'));
        $list = $accounts->sessions($uid, $current);
        $this->assertCount(3, $list);
        $this->assertSame([true], array_values(array_unique(array_column(array_filter($list, fn($s) => $s['id'] === $current), 'current'))));
        $this->assertSame('Device/1.0', $list[0]['user_agent']);

        $this->assertFalse($accounts->revokeSession($uid, SessionMetadata::hashId(str_pad('bob', 48, 'x'))), "can't revoke someone else's");
        $this->assertTrue($accounts->revokeSession($uid, SessionMetadata::hashId(str_pad('phone', 48, 'x'))));
        $this->assertSame(1, $accounts->revokeOtherSessions($uid, $current));
        $this->assertSame([$current], array_column($accounts->sessions($uid, $current), 'id'));
        $this->assertCount(1, $this->sessions->forUser($other), 'bob untouched');
        $this->assertSame(['auth.session_revoked', 'auth.session_revoked'], $this->auditEvents());
    }

    public function testPolicyEmailsAndHashing(): void
    {
        $policy = new PasswordPolicy(12);
        $this->assertSame([], $policy->errors('a long and unusual passphrase'));
        $this->assertContains('Use at least 12 characters.', $policy->errors('short'));
        $this->assertContains('That password is too common.', $policy->errors('Password-1234'));
        $this->assertContains('That password is too common.', $policy->errors('zzzzzzzzzzzzzz'));
        $this->assertContains("Don't use your username or email in your password.", $policy->errors('hello anamarkovic 2026', ['email' => 'anamarkovic@x.test']));
        $this->assertContains("Don't use your username or email in your password.", $policy->errors('my name is ananas forever', ['username' => 'ananas']));

        $this->cms->filters->add('auth.password.errors', static fn(array $errors, string $password) => str_contains($password, 'acme') ? [...$errors, 'No company names.'] : $errors);
        $this->assertContains('No company names.', $this->cms->container->make(PasswordPolicy::class)->errors('acme acme acme acme'));

        $this->assertSame('ana@example.com', Emails::normalize("  Ana@Example.COM\n"));
        $this->assertSame('a**@example.com', Emails::mask('ana@example.com'));
        $this->assertFalse(Emails::isValid('nope'));

        $this->assertTrue(str_starts_with(Hash::password('x'), '$argon2id$'));
        $this->assertTrue(Hash::needsRehash(password_hash('x', PASSWORD_BCRYPT)));
    }

    public function testTheServerCanSetAPasswordWhenMailCannot(): void
    {
        $uid = (int) $this->createUser()['id'];
        (new PersonalAccessTokens())->create($uid, 'laptop', ['content:read']);
        $run = function (array $args, array $options = [], string $stdin = ''): array {
            $in = fopen('php://memory', 'w+');
            fwrite($in, $stdin);
            rewind($in);
            $out = fopen('php://memory', 'w+');
            $command = new \Kestello\Cms\Console\SetPasswordCommand($in);
            $command->setOutput(new \Kestello\Framework\Console\Output($out, false));
            $code = $command->handle($args, $options);
            rewind($out);

            return [$code, (string) stream_get_contents($out)];
        };
        $login = $this->cms->container->make(LoginService::class);

        $this->assertSame(1, $run([])[0], 'needs a person');
        $this->assertSame(1, $run(['ana', 'a password on the command line'])[0], 'never takes the password as an argument');
        $this->assertSame(1, $run(['nobody'], [], "a long enough passphrase\n")[0]);
        $this->assertSame(1, $run(['ana'], [], "short\n")[0], 'the password rules apply');
        $this->assertTrue($login->attempt('ana', self::PASSWORD, '192.0.2.1')->ok(), 'nothing changed yet');

        [$code] = $run(['ana@example.test'], [], "a long enough passphrase\n");
        $this->assertSame(0, $code);
        $this->assertTrue($login->attempt('ana', 'a long enough passphrase', '192.0.2.1')->ok());
        $this->assertSame([], (new PersonalAccessTokens())->forUser($uid), 'like a reset: access tokens end');
        $this->assertContains('auth.password_changed', $this->auditEvents());

        [$code, $out] = $run(['ana'], ['generate' => true]);
        $this->assertSame(0, $code);
        $this->assertTrue((bool) preg_match('/^  ([A-Za-z0-9]{20})$/m', $out, $m), 'prints the new password once');
        $this->assertTrue($login->attempt('ana', $m[1], '192.0.2.1')->ok());
    }

    public function testTheServerCanCreateAnAccount(): void
    {
        $this->createUser();
        $run = function (array $args, array $options = [], string $stdin = ''): array {
            $in = fopen('php://memory', 'w+');
            fwrite($in, $stdin);
            rewind($in);
            $out = fopen('php://memory', 'w+');
            $command = new \Kestello\Cms\Console\CreateUserCommand($in);
            $command->setOutput(new \Kestello\Framework\Console\Output($out, false));
            $code = $command->handle($args, $options);
            rewind($out);

            return [$code, (string) stream_get_contents($out)];
        };
        $login = $this->cms->container->make(LoginService::class);
        $pw = "a long enough passphrase\n";

        $this->assertSame(1, $run([])[0], 'needs a username and an email');
        $this->assertSame(1, $run(['bob', 'bob@example.test', 'a password on the command line'])[0], 'never takes the password as an argument');
        $this->assertSame(1, $run(['ana', 'new@example.test'], [], $pw)[0], 'the username is taken');
        $this->assertSame(1, $run(['bob', 'ana@example.test'], [], $pw)[0], 'the email is taken');
        $this->assertSame(1, $run(['bo', 'bob@example.test'], [], $pw)[0], 'too short a username');
        $this->assertSame(1, $run(['bob', 'not-an-email'], [], $pw)[0]);
        $this->assertSame(1, $run(['bob', 'bob@example.test'], ['role' => 'emperor'], $pw)[0], 'a role that exists');
        $this->assertSame(1, $run(['bob', 'bob@example.test'], [], "short\n")[0], 'the password rules apply');
        $this->assertSame(1, $run(['bob', 'bob@example.test'], [], '')[0], 'no password, no account');
        $this->assertFalse(\Kestello\Framework\Database\QueryBuilder::table('users')->where('username', 'bob')->exists(), 'nothing was created by a refusal');

        [$code, $out] = $run(['bob', 'Bob@Example.TEST'], ['role' => 'admin'], $pw);
        $this->assertSame(0, $code, $out);
        $bo = \Kestello\Framework\Database\QueryBuilder::table('users')->where('username', 'bob')->first();
        $this->assertSame(['bob@example.test', 'admin', 1], [$bo['email'], $bo['role'], (int) $bo['is_active']]);
        $this->assertTrue($login->attempt('bob', 'a long enough passphrase', '192.0.2.1')->ok());
        $this->assertContains('users.created', $this->auditEvents());

        [$code, $out] = $run(['cyd', 'cyd@example.test'], ['generate' => true]);
        $this->assertSame(0, $code);
        $this->assertTrue((bool) preg_match('/^  ([A-Za-z0-9]{20})$/m', $out, $m), 'prints the new password once');
        $this->assertTrue($login->attempt('cyd', $m[1], '192.0.2.1')->ok());
        $this->assertSame('user', \Kestello\Framework\Database\QueryBuilder::table('users')->where('username', 'cyd')->value('role'), 'the default role is the least one');
    }

    public function testTheServerCanDeleteAnAccount(): void
    {
        $ana = (int) $this->createUser()['id'];
        $run = function (array $args, array $options = []): array {
            $out = fopen('php://memory', 'w+');
            $command = new \Kestello\Cms\Console\DeleteUserCommand();
            $command->setOutput(new \Kestello\Framework\Console\Output($out, false));
            $code = $command->handle($args, $options);
            rewind($out);

            return [$code, (string) stream_get_contents($out)];
        };
        $users = static fn(): \Kestello\Framework\Database\QueryBuilder => \Kestello\Framework\Database\QueryBuilder::table('users');

        $this->assertSame(1, $run([])[0], 'needs a person');
        $this->assertSame(1, $run(['nobody'], ['yes' => true])[0]);
        [$code, $out] = $run(['ana'], ['yes' => true]);
        $this->assertSame(1, $code, 'the last administrator stays');
        $this->assertStringContainsString('last administrator', $out);
        $this->assertTrue($users()->where('id', $ana)->exists());

        $shots = (int) $this->createUser(['username' => 'shots', 'email' => 'shots@example.test'])['id'];
        (new PersonalAccessTokens())->create($shots, 'laptop', ['content:read']);
        $this->assertSame(0, $run(['Shots@Example.TEST'], ['yes' => true])[0], 'found by email, any case');
        $this->assertFalse($users()->where('id', $shots)->exists());
        $this->assertSame([], (new PersonalAccessTokens())->forUser($shots), 'their access tokens end');
        $this->assertContains('users.deleted', $this->auditEvents());
        $this->assertTrue($users()->where('id', $ana)->exists(), 'nobody else is touched');
    }
}
