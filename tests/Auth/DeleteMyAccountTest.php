<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Api\Endpoints\Auth\DeleteMyAccount;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Events\UserDeleted;
use Kestello\Cms\Events\UserDeleting;
use Kestello\Framework\Database\QueryBuilder;

/**
 * DELETE /me: people delete their own account. UserDeleting lets a plugin refuse any deletion
 * with a reason, and UserDeleted tells plugins after any deletion: self, admin or cli.
 */
final class DeleteMyAccountTest extends AuthTestCase
{
    /** @var list<UserDeleted> */
    private array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms->events->on(UserDeleted::class, function (UserDeleted $e): void {
            $this->deleted[] = $e;
        });
    }

    public function testAMemberDeletesTheirOwnAccount(): void
    {
        $id = (int) $this->createUser(['role' => 'user'])['id'];
        $this->signIn();
        (new PersonalAccessTokens())->create($id, 'laptop', ['profile']);
        $this->addUnlinkedRows($id);

        $this->assertSame(204, $this->api('DELETE', '/me')->statusCode());

        $this->assertFalse(QueryBuilder::table('users')->where('id', $id)->exists());
        $this->assertSame([], (new PersonalAccessTokens())->forUser($id));
        $this->assertSame(['email_changes' => 0, 'notifications' => 0, 'notification_preferences' => 0], $this->unlinkedRows($id));
        $this->assertSame(401, $this->api('GET', '/me')->statusCode(), 'signed out');
        $this->assertContains('users.deleted', $this->auditEvents());
        $this->assertCount(1, $this->deleted);
        $this->assertSame([$id, 'ana', 'ana@example.test', 'self', $id], [$this->deleted[0]->userId, $this->deleted[0]->username, $this->deleted[0]->email, $this->deleted[0]->via, $this->deleted[0]->byUserId]);
        $this->assertCount(1, $this->mail);
        $this->assertSame('ana@example.test', $this->mail[0]['to']);
        $this->assertSame('Your Kestello account was deleted', $this->mail[0]['subject']);
        $this->assertStringContainsString('If you did not do this', $this->mail[0]['html']);
    }

    public function testItNeedsARecentSignInAndABrowser(): void
    {
        $user = $this->createUser(['role' => 'user']);
        $this->signIn();
        $_SESSION['_auth_at'] = time() - 660;
        $this->assertSame(401, $this->api('DELETE', '/me')->statusCode());
        $this->assertTrue(QueryBuilder::table('users')->where('id', $user['id'])->exists());

        $token = (new PersonalAccessTokens())->create((int) $user['id'], 'ci', ['profile'])['token'];
        $this->cms->auth->guard(new \Kestello\Cms\Auth\Guards\TokenGuard($this->cms->auth, new PersonalAccessTokens()));
        $this->nextRequest()->logout();
        $request = new \Kestello\Framework\Core\Request('DELETE', '/api/v1/me', '/api/v1/me', ['Authorization' => 'Bearer ' . $token], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertContains($this->cms->routes->dispatch($request)->statusCode(), [401, 403], 'never with an API token');
        $this->assertTrue(QueryBuilder::table('users')->where('id', $user['id'])->exists());
        $this->assertSame([], $this->deleted);
    }

    public function testTheLastAdministratorStays(): void
    {
        $this->createUser();
        $this->signIn();

        $response = $this->api('DELETE', '/me');

        $this->assertSame(409, $response->statusCode());
        $this->assertStringContainsString('last administrator', (string) $this->json($response)['detail']);
        $this->assertSame([], $this->deleted);
    }

    public function testAPluginRefusesWithAReasonEverywhere(): void
    {
        $seen = [];
        $this->cms->events->on(UserDeleting::class, static function (UserDeleting $e) use (&$seen): void {
            $seen[] = $e->via;
            $e->cancel('Hand over your organisations first.');
            $e->cancel('A second reason is not shown.');
        });
        $this->createUser();
        $bea = (int) $this->createUser(['username' => 'bea', 'email' => 'bea@example.test', 'role' => 'user'])['id'];

        $this->signIn();
        $byAdmin = $this->api('DELETE', '/users/' . $bea);
        $this->assertSame(409, $byAdmin->statusCode());
        $this->assertSame('Hand over your organisations first.', $this->json($byAdmin)['detail']);

        [$code, $out] = $this->cli(['bea']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Hand over your organisations first.', $out);

        $this->api('POST', '/auth/session', ['login' => 'bea', 'password' => self::PASSWORD]);
        $self = $this->api('DELETE', '/me');
        $this->assertSame(409, $self->statusCode());
        $this->assertSame('Hand over your organisations first.', $this->json($self)['detail']);

        $this->assertSame(['admin', 'cli', 'self'], $seen);
        $this->assertTrue(QueryBuilder::table('users')->where('id', $bea)->exists());
        $this->assertSame([], $this->deleted);
    }

    public function testPluginsHearOfEveryDeletion(): void
    {
        $admin = (int) $this->createUser()['id'];
        $bea = (int) $this->createUser(['username' => 'bea', 'email' => 'bea@example.test', 'role' => 'user'])['id'];
        $cy = (int) $this->createUser(['username' => 'cy', 'email' => 'cy@example.test', 'role' => 'user'])['id'];

        $this->signIn();
        $this->assertSame(204, $this->api('DELETE', '/users/' . $bea)->statusCode());
        $this->assertSame(0, $this->cli(['cy'])[0]);

        $this->assertSame([[$bea, 'admin', $admin], [$cy, 'cli', null]], array_map(static fn(UserDeleted $e): array => [$e->userId, $e->via, $e->byUserId], $this->deleted));
        $this->assertSame([], $this->mail, 'only a deletion you made yourself is emailed');
    }

    public function testTheGoodbyeEmail(): void
    {
        $message = DeleteMyAccount::goodbye('Acme');

        $this->assertSame('Your Acme account was deleted', $message->subject);
        $this->assertStringContainsString('Your Acme account and the data kept with it were deleted at your request.', $message->render('Acme'));
    }

    private function signIn(): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array{int, string} */
    private function cli(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $command = new \Kestello\Cms\Console\DeleteUserCommand();
        $command->setOutput(new \Kestello\Framework\Console\Output($out, false));
        $code = $command->handle($args, ['yes' => true]);
        rewind($out);

        return [$code, (string) stream_get_contents($out)];
    }
}
