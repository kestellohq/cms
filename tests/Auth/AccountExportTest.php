<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Api\Endpoints\Auth\ExportMyData;
use Kestello\Cms\Auth\AccountExport;
use Kestello\Cms\Auth\PersonalAccessTokens;

/**
 * GET /me/export: everything the site keeps about the person signed in, as one file, with no
 * secret in it, and what plugins add with the filter account.export.
 */
final class AccountExportTest extends AuthTestCase
{
    public function testTheFileHoldsTheAccountAndNoSecret(): void
    {
        $ana = $this->createUser(['role' => 'user', 'bio' => 'Hello']);
        $id = (int) $ana['id'];
        $bea = $this->createUser(['username' => 'bea', 'email' => 'bea@example.test']);
        $this->signIn();
        $this->sessionUser = $id;
        $this->sessions->write(str_pad('phone', 48, 'x'), 'x|i:1;');
        $token = (new PersonalAccessTokens())->create($id, 'deploy', ['content:read'])['token'];
        $this->db->table('user_identities')->insert(['user_id' => $id, 'provider' => 'github', 'subject' => '42', 'email' => 'ana@users.example', 'created_at' => 1794312000, 'last_used_at' => null]);
        $this->cms->audit->record('auth.custom', ['user', (string) $id], ['note' => 'about ana'], (int) $bea['id']);
        $this->cms->audit->record('user.updated', ['user', (string) $bea['id']], ['email' => 'bea@example.test'], $id);
        $this->cms->audit->record('auth.custom', ['user', (string) $bea['id']], ['note' => 'not about ana'], (int) $bea['id']);
        $form = $this->db->table('forms')->insert(['title' => 'Contact', 'status' => 'open', 'definition' => '{}', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $send = fn(array $answers, ?int $user = null) => $this->db->table('form_submissions')->insert(['form_id' => $form, 'data' => json_encode($answers), 'status' => 'new', 'ip' => '', 'user_agent' => '', 'page' => '/contact', 'user_id' => $user, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $send(['email' => 'ANA@example.test', 'message' => 'Hi']);
        $send(['email' => 'joana@example.test', 'message' => 'Not hers, though the address contains hers']);
        $send(['message' => 'Sent signed in'], $id);
        $send(['email' => 'bea@example.test', 'message' => 'Bea']);

        $response = $this->api('GET', '/me/export');

        $this->assertSame(200, $response->statusCode());
        $this->assertStringStartsWith('attachment; filename="', $response->headers()['Content-Disposition']);
        $this->assertSame('private, no-store', $response->headers()['Cache-Control']);
        $file = $this->json($response);
        $this->assertSame(AccountExport::FORMAT, $file['format']);
        $this->assertSame(['id' => $id, 'username' => 'ana', 'email' => 'ana@example.test', 'pending_email' => null, 'role' => 'user', 'active' => true, 'bio' => 'Hello', 'has_password' => true], array_intersect_key($file['account'], array_flip(['id', 'username', 'email', 'pending_email', 'role', 'active', 'bio', 'has_password'])));
        $this->assertSame([['provider' => 'github', 'email' => 'ana@users.example', 'connected_at' => '2026-11-10T12:00:00+00:00', 'last_used_at' => null]], $file['connected_sign_ins']);
        $this->assertSame('deploy', $file['api_tokens'][0]['name']);
        $this->assertSame(['content:read'], $file['api_tokens'][0]['scopes']);
        $this->assertGreaterThanOrEqual(1, count($file['sessions']));
        $this->assertSame(['ip', 'user_agent', 'signed_in_at', 'last_active_at'], array_keys($file['sessions'][0]), 'no session id');
        $this->assertFalse($file['two_factor']['enabled']);

        $audit = array_column($file['audit_log'], null, 'event');
        $this->assertSame(['note' => 'about ana'], $audit['auth.custom']['data'], 'an entry about her keeps its details');
        $this->assertNull($audit['user.updated']['data'], 'what she did to someone else keeps no details about them');
        $this->assertTrue($audit['user.updated']['by_you']);
        $this->assertNotContains('not about ana', array_map(static fn(array $e) => $e['data']['note'] ?? null, $file['audit_log']));

        $this->assertSame(['Hi', 'Sent signed in'], array_values(array_reverse(array_map(static fn(array $s) => $s['answers']['message'], $file['form_submissions']))), 'her address as an answer, or sent signed in; not a longer address that contains hers');
        $this->assertSame('Contact', $file['form_submissions'][0]['form']);

        $raw = $response->content();
        foreach ([$token, (string) $ana['password'], str_pad('phone', 48, 'x'), '"token_hash":', '"password":', '"secret":'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertContains('account.exported', $this->auditEvents());
    }

    public function testPluginsAddSectionsButCannotReplaceCore(): void
    {
        $this->createUser();
        $this->signIn();
        $this->cms->filters->add('account.export', static fn(array $data, array $user): array => ['events' => ['tickets' => 2], 'account' => ['username' => 'mallory']] + $data);

        $file = $this->json($this->api('GET', '/me/export'));

        $this->assertSame(['tickets' => 2], $file['events']);
        $this->assertSame('ana', $file['account']['username']);
    }

    public function testItNeedsARecentSignInAndASession(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $_SESSION['_auth_at'] = time() - 660;
        $this->assertSame(401, $this->api('GET', '/me/export')->statusCode());
        $this->assertSame(200, $this->api('POST', '/auth/confirm', ['password' => self::PASSWORD])->statusCode());
        $this->assertSame(200, $this->api('GET', '/me/export')->statusCode());

        $token = (new PersonalAccessTokens())->create((int) $user['id'], 'ci', ['profile'])['token'];
        $this->cms->auth->guard(new \Kestello\Cms\Auth\Guards\TokenGuard($this->cms->auth, new PersonalAccessTokens()));
        $this->nextRequest()->logout();
        $request = new \Kestello\Framework\Core\Request('GET', '/api/v1/me/export', '/api/v1/me/export', ['Authorization' => 'Bearer ' . $token], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertContains($this->cms->routes->dispatch($request)->statusCode(), [401, 403], 'never with an API token');
    }

    public function testTheFileName(): void
    {
        $this->assertSame('acme-studio-account-ana-b-2026-11-11.json', ExportMyData::filename('Acme Studio', 'Ana.B', '2026-11-11'));
        $this->assertSame('rm-account-x-2026-11-11.json', ExportMyData::filename('"; rm', 'x', '2026-11-11'));
    }

    private function signIn(): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }
}
