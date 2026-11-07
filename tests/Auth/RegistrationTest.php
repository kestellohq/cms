<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\AccountPolicy;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\LoginService;
use Kestello\Cms\Auth\Registrations;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/** Email sign-up: closed by default, address first, password after the link. */
final class RegistrationTest extends AuthTestCase
{
    private const string NEW_PASSWORD = 'a fresh new passphrase';

    /** @var list<UserRegistered> */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms->events->on(UserRegistered::class, fn(UserRegistered $e) => $this->registered[] = $e);
    }

    private function registrations(): Registrations
    {
        return $this->cms->container->make(Registrations::class);
    }

    private function open(string $policy = 'open'): void
    {
        $this->cms->config->set('auth.registration', $policy);
    }

    private function tokenFromMail(): string
    {
        preg_match('#/register/complete\?token=([A-Za-z0-9_-]{43})#', end($this->mail)['html'], $m);

        return $m[1] ?? '';
    }

    /** Sign up and open the link: the token the visitor holds. */
    private function signUp(string $email = 'new@example.test'): string
    {
        $this->assertSame([], $this->registrations()->request($email, '203.0.113.1'));

        return $this->tokenFromMail();
    }

    /** @param array<string, string> $body */
    private function web(string $method, string $uri, array $body = []): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = new Request($method, $uri, (string) parse_url($uri, PHP_URL_PATH), [], $query, $body, [], [], ['REMOTE_ADDR' => '203.0.113.7'], '');

        return $this->cms->routes->dispatch($request);
    }

    private function location(Response $response): ?string
    {
        return $response->headers()['Location'] ?? null;
    }

    public function testSignUpIsClosedUntilTheSiteOpensIt(): void
    {
        $this->assertFalse($this->registrations()->isOpen());
        $this->assertSame(['email'], array_keys($this->registrations()->request('new@example.test', '203.0.113.1')));
        $this->assertSame([], $this->mail);
        foreach ([['GET', '/register'], ['POST', '/register'], ['GET', '/register/complete'], ['POST', '/register/complete']] as [$method, $uri]) {
            $this->assertSame(404, $this->web($method, $uri, ['email' => 'new@example.test'])->statusCode(), "{$method} {$uri}");
        }

        $this->open();
        $this->assertTrue($this->registrations()->isOpen());
        $this->assertSame(200, $this->web('GET', '/register')->statusCode());
    }

    public function testTheAnswerNeverRevealsWhetherAnAddressHasAnAccount(): void
    {
        $this->open();
        $this->createUser();
        $this->createUser(['username' => 'off', 'email' => 'off@example.test', 'is_active' => 0]);

        $this->assertSame([], $this->registrations()->request(' Ana@EXAMPLE.test', '203.0.113.1'));
        $this->assertSame([], $this->registrations()->request('off@example.test', '203.0.113.1'));
        $this->assertSame([], $this->registrations()->request('new@example.test', '203.0.113.1'));

        // The owner of an existing account hears about it; a deactivated one gets nothing.
        $this->assertSame(['ana@example.test', 'new@example.test'], array_column($this->mail, 'to'));
        $this->assertStringContainsString('you already have one', $this->mail[0]['html']);
        $this->assertStringNotContainsString('token=', $this->mail[0]['html']);
        $this->assertSame(43, strlen($this->tokenFromMail()));

        $this->assertSame(['new@example.test'], $this->db->table(Registrations::TABLE)->pluck('email'), 'only the new address waits');
        $this->assertSame(hash('sha256', $this->tokenFromMail()), $this->db->table(Registrations::TABLE)->value('token_hash'), 'only the hash is stored');
        $this->assertSame(1, $this->db->table('users')->where('email', 'ana@example.test')->count(), 'no account is created before the link');
        $this->assertNull($this->db->table('users')->where('email', 'new@example.test')->first());
    }

    public function testTheLinkCreatesTheAccountWithThePasswordChosenThere(): void
    {
        $this->open();
        $first = $this->signUp();
        $token = $this->signUp();
        $this->assertNull($this->registrations()->find($first), 'a new link replaces the old one');
        $this->assertSame('new@example.test', $this->registrations()->find($token));

        $short = $this->registrations()->complete($token, 'short', '203.0.113.1');
        $this->assertSame(['password'], array_keys($short['errors']));
        $this->assertNull($short['user']);
        $this->assertSame('new@example.test', $this->registrations()->find($token), 'a refused password keeps the link');

        $done = $this->registrations()->complete($token, self::NEW_PASSWORD, '203.0.113.1');
        $this->assertSame([], $done['errors']);
        $this->assertSame(['new', 'new@example.test', 'user', 1], [$done['user']['username'], $done['user']['email'], $done['user']['role'], (int) $done['user']['is_active']]);
        $this->assertSame([[(int) $done['user']['id'], 'email']], array_map(static fn(UserRegistered $e) => [$e->userId, $e->via], $this->registered));

        $again = $this->registrations()->complete($token, 'another new passphrase', '203.0.113.1');
        $this->assertSame(['token'], array_keys($again['errors']), 'single use');
        $this->assertTrue($this->cms->container->make(LoginService::class)->attempt('new@example.test', self::NEW_PASSWORD, '192.0.2.1')->ok());
    }

    public function testTheUsernameComesFromTheAddressAndStaysUnique(): void
    {
        $this->open();
        $this->createUser(['username' => 'new', 'email' => 'someone@example.test']);

        $user = $this->registrations()->complete($this->signUp('New@Example.test'), self::NEW_PASSWORD, '203.0.113.1')['user'];

        $this->assertSame(['new2', 'new@example.test'], [$user['username'], $user['email']]);
    }

    public function testAnAddressTakenSinceTheLinkWasSentIsRefused(): void
    {
        $this->open();
        $token = $this->signUp();
        $this->createUser(['username' => 'quick', 'email' => 'new@example.test']);

        $result = $this->registrations()->complete($token, self::NEW_PASSWORD, '203.0.113.1');

        $this->assertSame(['token'], array_keys($result['errors']));
        $this->assertStringContainsString('already has an account', $result['errors']['token'][0]);
        $this->assertSame(1, $this->db->table('users')->where('email', 'new@example.test')->count());
        $this->assertSame([], $this->registered);
    }

    public function testExpiredAndMalformedLinks(): void
    {
        $this->open();
        $token = $this->signUp();
        $this->db->table(Registrations::TABLE)->update(['expires_at' => time() - 1]);

        $this->assertNull($this->registrations()->find($token));
        $this->assertSame(['token'], array_keys($this->registrations()->complete($token, self::NEW_PASSWORD, '203.0.113.1')['errors']));
        foreach (['', 'short', str_repeat('!', 43), $token . 'x'] as $bad) {
            $this->assertNull($this->registrations()->find($bad), $bad);
        }
    }

    public function testOnlyListedDomainsMaySignUp(): void
    {
        $this->open('domains');
        $this->cms->config->set('auth.registration_domains', 'example.org, Example.NET');

        $this->assertSame(['email' => ['Use an address at example.org, example.net.']], $this->registrations()->request('new@example.test', '203.0.113.1'));
        $this->assertSame(['example.org', 'example.net'], $this->registrations()->domains());
        $this->assertSame([], $this->mail);

        $this->assertNotNull($this->registrations()->complete($this->signUp('new@example.net'), self::NEW_PASSWORD, '203.0.113.1')['user']);
    }

    public function testThePolicyIsAskedAgainWhenTheLinkIsOpened(): void
    {
        $this->open();
        $token = $this->signUp();
        $this->open('closed');

        $result = $this->registrations()->complete($token, self::NEW_PASSWORD, '203.0.113.1');

        $this->assertSame(['token' => ['This site no longer accepts sign-ups for this address.']], $result['errors']);
        $this->assertNull($this->db->table('users')->where('email', 'new@example.test')->first());
    }

    public function testRoleAndFiltersAreSharedWithProviderSignIn(): void
    {
        $this->open();
        $this->cms->auth->roles->role('customer', 'Customer', ['read', 'profile.manage']);
        $this->cms->config->set('auth.default_role', 'customer');
        $this->assertSame('customer', $this->registrations()->complete($this->signUp('ed@example.test'), self::NEW_PASSWORD, '203.0.113.1')['user']['role']);

        $this->cms->config->set('auth.default_role', 'admin');
        $this->assertSame('user', $this->registrations()->complete($this->signUp('ad@example.test'), self::NEW_PASSWORD, '203.0.113.1')['user']['role'], 'never admin');

        $seen = [];
        $this->cms->filters->add('auth.can_register', function (bool $allowed, string $email, ?ExternalIdentity $identity) use (&$seen): bool {
            $seen[] = [$email, $identity];
            return $email !== 'spam@example.test';
        });
        $this->assertSame(['email'], array_keys($this->registrations()->request('spam@example.test', '203.0.113.1')));
        $this->assertSame([['spam@example.test', null]], $seen);
    }

    public function testRequestsAreRateLimitedPerAddress(): void
    {
        $this->open();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame([], $this->registrations()->request('new@example.test', '198.51.100.7'), 'the answer stays the same');
        }
        $this->assertCount(3, $this->mail, '3 an hour for an address from one place');
        $this->registrations()->request('new@example.test', '203.0.113.50');
        $this->assertCount(4, $this->mail, 'someone else asking for it does not silence the owner');
        for ($i = 0; $i < 30; $i++) {
            $this->registrations()->request('new@example.test', '192.0.2.' . $i);
        }
        $this->assertCount(10, $this->mail, 'and at most 10 an hour reach a mailbox in all');
    }

    public function testTheWebFlowSignsTheNewAccountIn(): void
    {
        $this->open();

        $sent = $this->web('POST', '/register', ['email' => 'new@example.test']);
        $this->assertSame([302, '/register'], [$sent->statusCode(), $this->location($sent)]);
        $this->assertSame(Registrations::SENT, $_SESSION['_flash']['new']['success'] ?? null);
        $token = $this->tokenFromMail();

        $page = $this->web('GET', '/register/complete?token=' . $token);
        $this->assertSame(200, $page->statusCode());
        $this->assertSame('no-referrer', $page->headers()['Referrer-Policy'] ?? null, 'the token must not leak through Referer');

        $mismatch = $this->web('POST', '/register/complete', ['token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'something else']);
        $this->assertSame('/register/complete?token=' . $token, $this->location($mismatch));
        $this->assertNull($this->db->table('users')->where('email', 'new@example.test')->first());

        $done = $this->web('POST', '/register/complete', ['token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        $this->assertSame([302, '/'], [$done->statusCode(), $this->location($done)]);
        $this->assertSame('new@example.test', Auth::getInstance()?->user()['email'] ?? null, 'signed in');
        $this->assertContains('auth.login', $this->auditEvents());
    }

    public function testThePolicyReadsTheAccountsSettings(): void
    {
        $this->assertSame('closed', AccountPolicy::current($this->cms)->registration);
        \Kestello\Framework\Settings\Settings::getInstance()->setMany(['auth.registration' => 'domains', 'auth.registration_domains' => 'Example.org'], 'accounts');

        $policy = AccountPolicy::current($this->cms);

        $this->assertSame(['domains', ['example.org']], [$policy->registration, $policy->domains]);
        $this->assertTrue($policy->allows('a@example.org'));
        $this->assertFalse($policy->allows('a@example.org.evil.test'));
    }
}
