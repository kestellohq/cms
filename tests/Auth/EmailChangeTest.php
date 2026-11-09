<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\EmailChanges;
use Kestello\Cms\Auth\PasswordResets;

/** A new email address only becomes the account's once a link sent to it is opened. */
final class EmailChangeTest extends AuthTestCase
{
    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private function changes(): EmailChanges
    {
        return $this->cms->container->make(EmailChanges::class);
    }

    private function tokenSentTo(string $to): string
    {
        foreach (array_reverse($this->mail) as $mail) {
            if ($mail['to'] === $to && preg_match('#/confirm-email\?token=([A-Za-z0-9_-]{43})#', $mail['html'], $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }

    /** @param array<string, mixed>|string $result */
    private function assertRefused(array|string $result, string $message = ''): void
    {
        $this->assertTrue(is_string($result), $message !== '' ? $message : 'the change is refused with a reason');
    }

    private function email(int $id): string
    {
        return (string) $this->db->table('users')->where('id', $id)->value('email');
    }

    public function testTheNewAddressConfirmsBeforeTheAccountUsesIt(): void
    {
        $ana = $this->createUser();
        $this->signIn();
        $this->assertSame(200, $this->api('PATCH', '/me', ['email' => 'Victim@Corp.test'])->statusCode());

        $this->assertSame('ana@example.test', $this->email($ana['id']), 'nothing changes yet');
        $this->assertSame('victim@corp.test', $this->changes()->pending($ana['id']));
        $this->assertSame(['victim@corp.test', 'ana@example.test'], array_column($this->mail, 'to'), 'a link to the new address, a notice to the old one');
        $this->assertStringNotContainsString('confirm-email', $this->mail[1]['html'], 'the old address gets no link');
        $token = $this->tokenSentTo('victim@corp.test');
        $this->assertSame(43, strlen($token));
        $this->assertFalse(str_contains((string) $this->db->table(EmailChanges::TABLE)->value('token_hash'), $token), 'only the hash is stored');

        $this->assertSame(['user_id' => $ana['id'], 'email' => 'victim@corp.test'], $this->changes()->confirm($token, '203.0.113.1'));
        $this->assertSame('victim@corp.test', $this->email($ana['id']));
        $this->assertNull($this->changes()->pending($ana['id']));
        $this->assertRefused($this->changes()->confirm($token, '203.0.113.1'), 'the link works once');
    }

    public function testTheConfirmLinkIsAWebPage(): void
    {
        $ana = $this->createUser();
        $this->signIn();
        $this->api('PATCH', '/me', ['email' => 'new@example.test']);
        $token = $this->tokenSentTo('new@example.test');

        $this->nextRequest();
        $uri = '/confirm-email?token=' . $token;
        $response = $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request('GET', $uri, '/confirm-email', [], ['token' => $token], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
        $this->assertSame(302, $response->statusCode());
        $this->assertSame('/admin/profile', $response->headers()['Location'] ?? null);
        $this->assertSame('new@example.test', $this->email($ana['id']));
        $this->assertContains('profile.email_confirmed', $this->auditEvents());
    }

    public function testConfirmingEndsResetLinksMailedToTheOldAddress(): void
    {
        $ana = $this->createUser();
        $this->cms->container->make(PasswordResets::class)->request('ana@example.test', '203.0.113.1');
        $this->assertSame(1, $this->db->table(PasswordResets::TABLE)->where('user_id', $ana['id'])->count());

        $this->assertNull($this->changes()->request($ana, 'new@example.test'));
        $this->changes()->confirm($this->tokenSentTo('new@example.test'), '203.0.113.1');
        $this->assertSame(0, $this->db->table(PasswordResets::TABLE)->where('user_id', $ana['id'])->count());
    }

    public function testAnAdministratorChangingTheAddressEndsResetLinksToo(): void
    {
        $this->createUser();
        $bob = $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->cms->container->make(PasswordResets::class)->request('bob@example.test', '203.0.113.1');
        $this->assertNull($this->changes()->request($bob, 'bob.new@example.test'));
        $this->signIn();

        $this->assertSame(200, $this->api('PATCH', "/users/{$bob['id']}", ['email' => 'bob.fixed@example.test'])->statusCode());
        $this->assertSame('bob.fixed@example.test', $this->email($bob['id']), 'an administrator sets it directly');
        $this->assertSame(0, $this->db->table(PasswordResets::TABLE)->where('user_id', $bob['id'])->count());
        $this->assertNull($this->changes()->pending($bob['id']), 'and the waiting change is dropped');
    }

    public function testRefusedChanges(): void
    {
        $ana = $this->createUser();
        $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->assertSame('This email is already in use.', $this->changes()->request($ana, 'BOB@example.test'));
        $this->assertSame('Enter a valid email address.', $this->changes()->request($ana, 'not an email'));
        $this->assertSame([], $this->mail);

        // taken while the link waited
        $this->assertNull($this->changes()->request($ana, 'later@example.test'));
        $token = $this->tokenSentTo('later@example.test');
        $this->db->table('users')->where('username', 'bob')->update(['email' => 'later@example.test']);
        $this->assertSame('This email is now used by another account.', $this->changes()->confirm($token, '203.0.113.1'));
        $this->assertSame('ana@example.test', $this->email($ana['id']));

        foreach (['', 'short', str_repeat('a', 43)] as $bad) {
            $this->assertRefused($this->changes()->confirm($bad, '203.0.113.1'));
        }
    }

    public function testALinkExpires(): void
    {
        $ana = $this->createUser();
        $this->assertNull($this->changes()->request($ana, 'new@example.test'));
        $this->db->table(EmailChanges::TABLE)->update(['expires_at' => time() - 1]);
        $this->assertNull($this->changes()->pending($ana['id']));
        $this->assertRefused($this->changes()->confirm($this->tokenSentTo('new@example.test'), '203.0.113.1'));
        $this->assertSame('ana@example.test', $this->email($ana['id']));
    }

    public function testRequestsAreLimited(): void
    {
        $ana = $this->createUser();
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($this->changes()->request($ana, "new{$i}@example.test"));
        }
        $this->assertSame('Too many changes asked for. Try again later.', $this->changes()->request($ana, 'new9@example.test'));
        $this->assertSame(1, $this->db->table(EmailChanges::TABLE)->count(), 'a new request replaces the waiting one');
    }
}
