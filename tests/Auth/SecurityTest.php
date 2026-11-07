<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Framework\Session\SessionMetadata;

/** System → Security: two-step sign-in coverage, failed sign-ins, everyone's sessions and tokens, recent security events. */
final class SecurityTest extends AuthTestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->people['ana'] = $this->createUser();
        $this->people['dora'] = $this->createUser(['username' => 'dora', 'email' => 'dora@example.test']);
        $this->people['bob'] = $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->db->table('mfa_methods')->insert(['user_id' => $this->people['dora']['id'], 'type' => 'totp', 'secret' => 'sealed', 'created_at' => time(), 'confirmed_at' => time()]);
        // an enrollment never finished is not two-step sign-in
        $this->db->table('mfa_methods')->insert(['user_id' => $this->people['ana']['id'], 'type' => 'totp', 'secret' => 'sealed', 'created_at' => time()]);
    }

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private function sessionFor(string $who, string $device): string
    {
        $this->sessionUser = (int) $this->people[$who]['id'];
        $raw = str_pad($who . '-' . $device, 48, 'x');
        $this->sessions->write($raw, 'x|i:1;');
        $this->sessionUser = null;

        return SessionMetadata::hashId($raw);
    }

    public function testTwoStepCoverageFailedSignInsAndRecentEvents(): void
    {
        $ip = null;    // the Kernel records the client's address with each audit row; stand in for it
        $this->cms->audit->setContextProvider(static function () use (&$ip): array {
            return ['actor_id' => null, 'ip' => $ip, 'user_agent' => 'test', 'request_id' => null];
        });
        foreach ([['bob', '198.51.100.20'], ['nobody@example.test', '198.51.100.20'], ['bob', '192.0.2.4']] as [$login, $ip]) {
            $this->api('POST', '/auth/session', ['login' => $login, 'password' => 'wrong password here'], ip: $ip);
        }
        $ip = '203.0.113.7';
        $this->db->table('audit_log')->insert(['occurred_at' => time() - 10 * 86400, 'event' => 'auth.login_failed', 'owner' => 'core', 'ip' => '192.0.2.99', 'data' => '{"reason":"credentials"}']);
        $this->signIn();

        $r = $this->api('GET', '/security');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Security\GetSecurity::class, $r);
        $data = $this->json($r)['data'];

        $two = $data['two_factor'];
        $this->assertSame(['admin'], $two['roles']);
        $this->assertSame([2, 1], [$two['required'], $two['covered']], 'two administrators, one with two-step sign-in');
        $this->assertSame([['id' => (int) $this->people['ana']['id'], 'username' => 'ana', 'role' => 'admin']], $two['missing'], 'an unfinished enrollment does not count');
        $this->assertSame([3, 1], [$two['everyone']['total'], $two['everyone']['covered']]);
        $this->assertSame('Administrator', $data['role_labels']['admin'], 'labels come along: this page may not read roles');

        $failed = $data['failed_sign_ins'];
        $this->assertSame([3, 3], [$failed['day'], $failed['week']], 'the one from ten days ago is outside both');
        $this->assertSame([['ip' => '198.51.100.20', 'count' => 2], ['ip' => '192.0.2.4', 'count' => 1]], $failed['top_ips']);
        $this->assertSame(['credentials', 'credentials', 'credentials', 'credentials'], array_column($failed['recent'], 'reason'));
        $this->assertSame(['bob', null, 'bob', null], array_map(static fn($f) => $f['user']['username'] ?? null, $failed['recent']), 'newest first; unknown accounts are not named');
        $this->assertSame('192.0.2.99', end($failed['recent'])['ip'], 'the latest ones, however old');
        $this->assertStringNotContainsString('nobody', $r->content(), 'what was typed into the login field never comes back');

        $events = array_column($data['events'], 'event');
        $this->assertContains('auth.login', $events);
        $this->assertNotContains('auth.login_failed', $events, 'failed sign-ins have their own card');
        $this->assertSame('ana', $data['events'][0]['actor']['username'] ?? null);
    }

    public function testEveryonesSessionsCanBeListedAndEnded(): void
    {
        $laptop = $this->sessionFor('bob', 'laptop');
        $phone = $this->sessionFor('bob', 'phone');
        $doras = $this->sessionFor('dora', 'desk');
        $this->signIn();

        $r = $this->api('GET', '/security/sessions');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Security\ListSecuritySessions::class, $r);
        $body = $this->json($r);
        $this->assertTrue($body['meta']['supported']);
        $byId = array_column($body['data'], null, 'id');
        $this->assertSame(['bob', 'bob', 'dora'], [$byId[$laptop]['user']['username'], $byId[$phone]['user']['username'], $byId[$doras]['user']['username']]);
        $this->assertSame('Device/1.0', $byId[$laptop]['user_agent']);

        $bob = (int) $this->people['bob']['id'];
        $this->assertSame(404, $this->api('DELETE', "/security/sessions/{$bob}/{$doras}")->statusCode(), "dora's session is not bob's");
        $this->assertSame(204, $this->api('DELETE', "/security/sessions/{$bob}/{$laptop}")->statusCode());
        $this->assertSame([$phone], array_column($this->sessions->forUser($bob), 'id'));
        $this->assertContains('auth.session_revoked', $this->auditEvents());

        $all = $this->api('DELETE', "/security/sessions/{$bob}");
        $this->assertSame(200, $all->statusCode(), $all->content());
        $this->assertSame(1, $this->json($all)['data']['signed_out']);
        $this->assertSame([], $this->sessions->forUser($bob));
        $this->assertCount(1, $this->sessions->forUser((int) $this->people['dora']['id']), 'others untouched');
    }

    public function testEveryonesTokensCanBeListedAndRevoked(): void
    {
        $tokens = $this->cms->container->make(PersonalAccessTokens::class);
        $made = $tokens->create((int) $this->people['bob']['id'], 'Deploy script', ['content:read']);
        $this->signIn();

        $r = $this->api('GET', '/security/tokens');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Security\ListSecurityTokens::class, $r);
        $row = $this->json($r)['data'][0];
        $this->assertSame(['Deploy script', 'bob', ['content:read'], false], [$row['name'], $row['user']['username'], $row['scopes'], $row['expired']]);
        $this->assertStringNotContainsString(PersonalAccessTokens::PREFIX, $r->content(), 'secrets never come back');
        $this->assertArrayNotHasKey('token_hash', $row);

        $this->assertSame(204, $this->api('DELETE', "/security/tokens/{$row['id']}")->statusCode());
        $this->assertNull($tokens->find((int) $row['id']));
        $this->assertNull($tokens->verify($made['token']));
        $this->assertContains('auth.token_revoked', $this->auditEvents());
        $this->assertSame(404, $this->api('DELETE', "/security/tokens/{$row['id']}")->statusCode());
    }

    public function testOnlyPeopleWhoManageSecurityGetIn(): void
    {
        $this->signIn('bob');
        foreach (['/security', '/security/sessions', '/security/tokens'] as $path) {
            $this->assertSame(403, $this->api('GET', $path)->statusCode(), $path);
        }
        $this->assertSame(403, $this->api('DELETE', '/security/tokens/1')->statusCode());
        $this->assertContains('security.manage', array_keys(\Kestello\Cms\Auth\Roles::CORE));

        // An administrator's token needs the security scope.
        $tokens = new PersonalAccessTokens();
        $this->cms->auth->guard(new \Kestello\Cms\Auth\Guards\TokenGuard($this->cms->auth, $tokens));
        $ana = (int) $this->people['ana']['id'];
        foreach ([[['content:read'], 403], [['security'], 200]] as [$scopes, $status]) {
            $this->nextRequest();
            $_SESSION = [];
            $token = $tokens->create($ana, 'scripts', $scopes)['token'];
            $request = new \Kestello\Framework\Core\Request('GET', '/api/v1/security', '/api/v1/security', ['Authorization' => 'Bearer ' . $token], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
            $this->assertSame($status, $this->cms->routes->dispatch($request)->statusCode(), implode(',', $scopes));
        }
    }
}
