<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

/** Managing other people's accounts and the site settings over the API. */
final class UserAdminApiTest extends AuthTestCase
{
    private const string OTHER = 'a much longer passphrase';

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    public function testCreateUpdateAndDeleteUsers(): void
    {
        $this->createUser();
        $this->signIn();

        $weak = $this->api('POST', '/users', ['username' => 'bo', 'email' => 'x', 'password' => 'short', 'role' => 'nobody']);
        $this->assertSame(422, $weak->statusCode());
        $this->assertSame(['email', 'role', 'username'], (static function (array $k) {
            sort($k);
            return $k;
        })(array_keys($this->json($weak)['errors'])));

        $created = $this->api('POST', '/users', ['username' => 'bob', 'email' => ' Bob@Example.test ', 'password' => self::OTHER, 'role' => 'user']);
        $this->assertSame(201, $created->statusCode());
        $bob = $this->json($created)['data'];
        $this->assertSame(['bob', 'bob@example.test', 'user', true], [$bob['username'], $bob['email'], $bob['role'], $bob['is_active']]);
        $this->assertFalse(array_key_exists('password', $bob));
        $this->assertSame('User', $bob['role_label'], 'the name people see, so lists need not read the roles');

        $dupe = $this->api('POST', '/users', ['username' => 'bob', 'email' => 'bob@example.test', 'password' => 'short', 'role' => 'user']);
        $this->assertSame(['email', 'password', 'username'], (static function (array $k) {
            sort($k);
            return $k;
        })(array_keys($this->json($dupe)['errors'])));

        $edit = $this->api('PATCH', "/users/{$bob['id']}", ['bio' => 'Bakes.', 'is_active' => false]);
        $this->assertSame(200, $edit->statusCode());
        $this->assertSame(['Bakes.', false], [$this->json($edit)['data']['bio'], $this->json($edit)['data']['is_active']]);

        $this->addUnlinkedRows($bob['id']);
        $this->assertSame(204, $this->api('DELETE', "/users/{$bob['id']}")->statusCode());
        $this->assertSame(404, $this->api('GET', "/users/{$bob['id']}")->statusCode());
        $this->assertSame(['email_changes' => 0, 'notifications' => 0, 'notification_preferences' => 0], $this->unlinkedRows($bob['id']), 'their waiting email change, inbox and preferences go with the account');
        $this->assertSame(['users.created', 'users.updated', 'users.deleted'], array_values(array_filter($this->auditEvents(), static fn(string $e) => str_starts_with($e, 'users.'))));
    }

    public function testYouCannotLockYourselfOrTheSiteOut(): void
    {
        $me = $this->createUser();
        $this->signIn();

        $self = $this->json($this->api('PATCH', "/users/{$me['id']}", ['role' => 'user', 'is_active' => false, 'password' => self::OTHER]))['errors'];
        $this->assertSame(['is_active', 'password', 'role'], (static function (array $k) {
            sort($k);
            return $k;
        })(array_keys($self)));
        $this->assertSame(409, $this->api('DELETE', "/users/{$me['id']}")->statusCode());

        // A second admin, deactivated: ana is still the last *active* one.
        $other = $this->createUser(['username' => 'cy', 'email' => 'cy@example.test', 'is_active' => 0]);
        $this->assertSame(200, $this->api('PATCH', "/users/{$other['id']}", ['role' => 'user'])->statusCode());
        $this->assertSame('user', $this->db->table('users')->where('id', $other['id'])->value('role'));
    }

    public function testManagersWithoutEverythingCannotTouchAdministrators(): void
    {
        $this->cms->auth->roles->role('manager', 'Manager', ['read', 'profile.manage', 'admin.access', 'users.read', 'users.manage']);
        $this->createUser(['username' => 'mo', 'email' => 'mo@example.test', 'role' => 'manager']);
        $admin = $this->createUser();
        $this->signIn('mo');

        $this->assertSame(403, $this->api('POST', '/users', ['username' => 'evil', 'email' => 'e@example.test', 'password' => self::OTHER, 'role' => 'admin'])->statusCode());
        $this->assertSame(403, $this->api('PATCH', "/users/{$admin['id']}", ['password' => self::OTHER])->statusCode());
        $this->assertSame(403, $this->api('DELETE', "/users/{$admin['id']}")->statusCode());

        $user = $this->json($this->api('POST', '/users', ['username' => 'dee', 'email' => 'd@example.test', 'password' => self::OTHER, 'role' => 'user']))['data'];
        $this->assertSame(403, $this->api('PATCH', "/users/{$user['id']}", ['role' => 'admin'])->statusCode(), 'no promotion to admin');

        $roles = array_column($this->json($this->api('GET', '/roles'))['data'], 'assignable', 'name');
        $this->assertSame(['admin' => false, 'user' => true, 'manager' => true], $roles);
    }

    public function testManagersCannotReachRolesMorePowerfulThanTheirOwn(): void
    {
        $this->cms->auth->roles->role('manager', 'Manager', ['read', 'profile.manage', 'admin.access', 'users.read', 'users.manage', 'roles.manage']);
        $this->cms->auth->roles->role('operator', 'Operator', ['read', 'admin.access', 'settings.manage', 'system.manage']);
        $this->createUser();
        $this->createUser(['username' => 'mo', 'email' => 'mo@example.test', 'role' => 'manager']);
        $op = $this->createUser(['username' => 'op', 'email' => 'op@example.test', 'role' => 'operator']);
        $this->signIn('mo');

        $this->assertSame(403, $this->api('PATCH', "/users/{$op['id']}", ['password' => self::OTHER])->statusCode(), 'no takeover');
        $this->assertSame(403, $this->api('PATCH', "/users/{$op['id']}", ['email' => 'mine@example.test'])->statusCode());
        $this->assertSame(403, $this->api('DELETE', "/users/{$op['id']}")->statusCode());
        $this->assertSame(403, $this->api('POST', '/users', ['username' => 'op2', 'email' => 'o2@example.test', 'password' => self::OTHER, 'role' => 'operator'])->statusCode(), 'no new operator');
        $dee = $this->json($this->api('POST', '/users', ['username' => 'dee', 'email' => 'd@example.test', 'password' => self::OTHER, 'role' => 'user']))['data'];
        $this->assertSame(403, $this->api('PATCH', "/users/{$dee['id']}", ['role' => 'operator'])->statusCode(), 'no promotion');

        $roles = array_column($this->json($this->api('GET', '/roles'))['data'], 'assignable', 'name');
        $this->assertFalse($roles['operator']);
        $this->assertTrue($roles['user']);
        $editable = array_column($this->json($this->api('GET', '/roles'))['data'], 'editable', 'name');
        $this->assertFalse($editable['operator'], 'the admin shows it as not editable');
        $this->assertTrue($editable['user']);

        // narrowing the stronger role, giving someone it, then resetting it would hand out its capabilities
        $this->assertSame(403, $this->api('PATCH', '/roles/operator', ['capabilities' => ['read', 'admin.access']])->statusCode(), 'no narrowing a stronger role');
        $this->assertSame(403, $this->api('DELETE', '/roles/operator')->statusCode(), 'nor resetting it');
        $this->assertSame(['admin.access', 'read', 'settings.manage', 'system.manage'], $this->sorted($this->cms->auth->roles->capabilitiesFor('operator')));
    }

    /**
     * @param list<string> $caps
     * @return list<string>
     */
    private function sorted(array $caps): array
    {
        sort($caps);

        return $caps;
    }

    public function testAdminPagesAreForBrowserSessionsOnly(): void
    {
        $this->createUser();
        $page = static fn(string $auth) => (new \Kestello\Cms\Auth\AdminMiddleware())->handle(
            new \Kestello\Framework\Core\Request('GET', '/admin', '/admin', ['Authorization' => $auth], [], [], [], [], []),
            static fn() => \Kestello\Framework\Core\Response::html('admin'),
        );
        $this->nextRequest();
        $bad = $page('Bearer junk');
        $this->assertSame(302, $bad->statusCode(), 'a bad token is a sign-in redirect, not a 500');
        $token = $this->cms->container->make(\Kestello\Cms\Auth\PersonalAccessTokens::class)->create(1, 'test', ['admin'])['token'];
        $this->nextRequest();
        $this->assertSame(302, $page('Bearer ' . $token)->statusCode(), 'a valid token does not open admin HTML');
    }

    public function testNewPasswordOrDeactivationEndsTheirAccess(): void
    {
        $this->createUser();
        $bob = $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->sessionUser = (int) $bob['id'];
        $this->sessions->write(bin2hex(random_bytes(24)), 'x|i:1;');
        $this->sessionUser = null;
        $this->assertCount(1, $this->sessions->forUser((int) $bob['id']));

        $this->signIn();
        $this->assertSame(200, $this->api('PATCH', "/users/{$bob['id']}", ['is_active' => false])->statusCode());
        $this->assertSame([], $this->sessions->forUser((int) $bob['id']));
    }

    public function testSettingsReadAndWrite(): void
    {
        $this->createUser();
        $this->signIn();

        $this->assertSame(200, $this->api('PATCH', '/settings', ['general' => ['site_name' => 'Harbour'], 'mail' => ['smtp_host' => 'smtp.example.test', 'smtp_port' => 465, 'smtp_password' => 'pw-1']])->statusCode());
        $read = $this->json($this->api('GET', '/settings'));
        $this->assertSame('Harbour', $read['data']['general']['site_name']);
        $this->assertSame([465, true], [$read['data']['mail']['smtp_port'], $read['data']['mail']['smtp_password_set']]);
        $this->assertFalse(str_contains(json_encode($read), 'pw-1'), 'the password never leaves the server');
        $this->assertSame(['general', 'reading', 'seo', 'mail', 'emails', 'accounts', 'media', 'performance', 'snippets'], array_column($read['meta']['groups'], 'id'));
        $this->assertTrue(in_array('Europe/Belgrade', $read['meta']['groups'][0]['schema']['properties']['timezone']['enum'], true));

        $this->api('PATCH', '/settings', ['mail' => ['smtp_password' => '']]);
        $this->assertTrue($this->json($this->api('GET', '/settings'))['data']['mail']['smtp_password_set'], 'blank keeps it');
        $this->api('PATCH', '/settings', ['mail' => ['smtp_password' => null]]);
        $this->assertFalse($this->json($this->api('GET', '/settings'))['data']['mail']['smtp_password_set'], 'null clears it');

        $bad = $this->api('PATCH', '/settings', ['general' => ['timezone' => 'Mars/Olympus', 'site_url' => 'ftp://x.example']]);
        $this->assertSame(422, $bad->statusCode());
        $this->assertSame(['general.site_url', 'general.timezone'], (static function (array $k) {
            sort($k);
            return $k;
        })(array_keys($this->json($bad)['errors'])));
        $this->assertSame(2, count(array_filter($this->auditEvents(), static fn($e) => $e === 'settings.updated')), 'a no-op save is not audited');
    }

    public function testUpdateOwnProfile(): void
    {
        $this->createUser();
        $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->signIn();

        $this->assertSame(422, $this->api('PATCH', '/me', ['email' => 'bob@example.test'])->statusCode());
        $ok = $this->json($this->api('PATCH', '/me', ['email' => 'Ana.New@example.test', 'bio' => 'Runs the place.']))['data'];
        $this->assertSame(['ana@example.test', 'Runs the place.'], [$ok['email'], $ok['bio']], 'the bio is saved; the email waits for its link');
        $this->assertSame('ana.new@example.test', $this->json($this->api('GET', '/me'))['data']['pending_email']);
    }

    public function testAccountsSettingsAreWhatSignUpReads(): void
    {
        $this->createUser();
        $this->signIn();
        $this->assertSame('closed', $this->json($this->api('GET', '/settings'))['data']['accounts']['registration']);

        $this->assertSame(200, $this->api('PATCH', '/settings', ['accounts' => ['registration' => 'domains', 'registration_domains' => 'Example.org, example.net']])->statusCode());

        $policy = \Kestello\Cms\Auth\AccountPolicy::current($this->cms);
        $this->assertSame(['domains', ['example.org', 'example.net']], [$policy->registration, $policy->domains]);
        $this->assertSame(422, $this->api('PATCH', '/settings', ['accounts' => ['default_role' => 'admin']])->statusCode(), 'admin is never a choice');
    }

    public function testPluginSettingsTabs(): void
    {
        \Kestello\Framework\Core\ExecutionContext::run('plugin:acme', function (): void {
            $this->cms->admin->settings('api', 'Acme', \Kestello\Cms\Api\Schema::object([
                'endpoint' => \Kestello\Cms\Api\Schema::string()->format('uri')->default('https://api.acme.test'),
                'retries' => \Kestello\Cms\Api\Schema::int()->min(0)->max(5)->default(2),
                'enabled' => \Kestello\Cms\Api\Schema::bool()->default(false),
                'key' => \Kestello\Cms\Api\Schema::string()->max(200),
            ]), secrets: ['key']);
            $this->cms->admin->settings('vault', 'Acme vault', \Kestello\Cms\Api\Schema::object(['pin' => \Kestello\Cms\Api\Schema::string()]), capability: 'acme.vault');
        });
        $this->cms->auth->roles->role('editor', 'Editor', ['read', 'admin.access', 'settings.manage']);
        $this->createUser();
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->signIn();

        $read = $this->json($this->api('GET', '/settings'));
        $this->assertSame(['general', 'reading', 'seo', 'mail', 'emails', 'accounts', 'media', 'performance', 'snippets', 'acme.api', 'acme.vault'], array_column($read['meta']['groups'], 'id'));
        $this->assertSame(['endpoint' => 'https://api.acme.test', 'retries' => 2, 'enabled' => false, 'key_set' => false], $read['data']['acme.api'], 'defaults until saved');

        // A partial save: absent fields keep their values (schema defaults are not applied on PATCH).
        $this->assertSame(200, $this->api('PATCH', '/settings', ['acme.api' => ['enabled' => true, 'key' => 'k-123']])->statusCode());
        $this->assertSame(200, $this->api('PATCH', '/settings', ['acme.api' => ['retries' => 4]])->statusCode());
        $data = $this->json($this->api('GET', '/settings'))['data']['acme.api'];
        $this->assertSame(['endpoint' => 'https://api.acme.test', 'retries' => 4, 'enabled' => true, 'key_set' => true], $data);

        // Stored under the plugin prefix, the secret sealed for exactly that name.
        $stored = $this->db->table('settings')->where('key', 'plugin.acme.api.key')->value('value');
        $this->assertFalse(str_contains((string) $stored, 'k-123'));
        $this->assertSame('k-123', \Kestello\Cms\Extension\PluginProvider::openSecret('plugin.acme.api.key', (string) $stored));
        $this->assertSame('1', $this->db->table('settings')->where('key', 'plugin.acme.api.enabled')->value('value'));

        $bad = $this->api('PATCH', '/settings', ['acme.api' => ['retries' => 9]]);
        $this->assertSame(['acme.api.retries'], array_keys($this->json($bad)['errors']));

        // Someone with settings.manage but not acme.vault neither sees nor changes that tab.
        $this->signIn('ed');
        $this->assertSame(['general', 'reading', 'seo', 'media', 'performance', 'acme.api'], array_column($this->json($this->api('GET', '/settings'))['meta']['groups'], 'id'));
        $this->assertSame(403, $this->api('PATCH', '/settings', ['acme.vault' => ['pin' => '1234']])->statusCode());
        $this->assertSame(403, $this->api('PATCH', '/settings', ['emails' => ['email_footer' => 'Hi']])->statusCode(), 'emails need emails.manage');

        // Reset links travel through the mail server and are built on the site address: neither is a settings manager's to move.
        $this->assertSame(403, $this->api('PATCH', '/settings', ['mail' => ['smtp_host' => 'mail.attacker.test']])->statusCode());
        $this->assertSame(403, $this->api('PATCH', '/settings', ['general' => ['site_url' => 'https://attacker.test']])->statusCode());
        $this->assertSame(200, $this->api('PATCH', '/settings', ['general' => ['site_name' => 'Bakery']])->statusCode(), 'the rest of the general tab stays theirs');
    }
}
