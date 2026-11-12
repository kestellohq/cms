<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\Registrations;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Cms\Notifications\NotificationsModule;

/** Someone made their own account: the people who manage people hear about it, once, and can turn it off. */
final class SignUpNotificationTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new NotificationsModule())->boot($this->cms);
    }

    /** @return list<array<string, mixed>> */
    private function notices(int $userId): array
    {
        return array_map(static fn($r): array => json_decode((string) ((array) $r)['data'], true), $this->db->table('notifications')->where('user_id', $userId)->where('type', 'new-sign-up')->get());
    }

    private function signUp(): array
    {
        $this->cms->config->set('auth.registration', 'open');
        $registrations = $this->cms->container->make(Registrations::class);
        $registrations->request('new@example.test', '203.0.113.1');
        preg_match('#token=([A-Za-z0-9_-]{43})#', end($this->mail)['html'], $m);
        $this->mail = [];

        return $registrations->complete($m[1], 'a fresh new passphrase', '203.0.113.1')['user'];
    }

    public function testThePeopleWhoManagePeopleHearAboutAnEmailSignUp(): void
    {
        $admin = $this->createUser();
        $editor = $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'role' => 'editor']);
        $this->cms->config->set('app.url', 'https://studio.example');

        $user = $this->signUp();

        $this->assertSame([['title' => 'new signed up', 'body' => 'new@example.test made an account with the sign-up form.', 'url' => '/admin/users/' . $user['id'], 'icon' => 'user']], $this->notices((int) $admin['id']));
        $this->assertSame([], $this->notices((int) $editor['id']), 'an editor cannot manage people');
        $this->assertSame([], $this->notices((int) $user['id']));
        $this->assertSame(['ana@example.test'], array_column($this->mail, 'to'));
        $this->assertSame('new signed up', $this->mail[0]['subject']);
        $this->assertStringContainsString('href="https://studio.example/admin/users/' . $user['id'] . '"', $this->mail[0]['html']);
    }

    public function testAProviderSignUpNamesTheProvider(): void
    {
        $admin = $this->createUser();
        $id = $this->createUser(['username' => 'cy', 'email' => 'cy@example.test', 'role' => 'user'])['id'];

        $this->cms->events->dispatch(new UserRegistered((int) $id, 'external:acme-sso'));

        $this->assertSame('cy@example.test made an account by signing in with acme-sso.', $this->notices((int) $admin['id'])[0]['body'], 'an uninstalled provider: its id');
    }

    public function testAccountsMadeBySomeoneElseAreNotAnnounced(): void
    {
        $admin = $this->createUser();
        $id = (int) $this->createUser(['username' => 'cy', 'email' => 'cy@example.test', 'role' => 'user'])['id'];

        foreach (['admin', 'cli'] as $via) {
            $this->cms->events->dispatch(new UserRegistered($id, $via));
        }

        $this->assertSame([], $this->notices((int) $admin['id']));
        $this->assertSame([], $this->mail);
    }

    public function testItCanBeTurnedOffByEmailAndInTheAdmin(): void
    {
        $admin = $this->createUser();
        $this->cms->notifications->setPreference((int) $admin['id'], 'new-sign-up', 'mail', false);

        $this->signUp();

        $this->assertCount(1, $this->notices((int) $admin['id']));
        $this->assertSame([], $this->mail);
        $this->assertSame(['users.manage'], $this->cms->notifications->types()['new-sign-up']['capabilities'] ?? null, 'listed in the preferences of people who can manage people');
    }
}
