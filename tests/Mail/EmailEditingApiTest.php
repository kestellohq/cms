<?php

declare(strict_types=1);

namespace Kestello\Tests\Mail;

use Kestello\Cms\Auth\PasswordResets;
use Kestello\Cms\Mail\EmailEdits;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;
use Kestello\Tests\Auth\AuthTestCase;

/** Settings → Emails through the API: list, read, edit per language, preview, test, undo. */
final class EmailEditingApiTest extends AuthTestCase
{
    private const array EDIT = [
        'subject' => 'A new password for {site}',
        'blocks' => [
            ['type' => 'heading', 'text' => 'Hello {name}'],
            ['type' => 'original', 'name' => 'link', 'label' => 'Choose a password'],
            ['type' => 'original', 'name' => 'expiry'],
            ['type' => 'original', 'name' => 'safety'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $pack = $this->tempDir();
        file_put_contents("{$pack}/sr.json", json_encode(['Reset your {site} password' => 'Nova lozinka za {site}']));
        Translator::addPath('core', $pack);
        Settings::getInstance()->set('site_name', 'Studio Ana');
        Settings::getInstance()->set('site_url', 'https://studio.test');
        $this->bootCms();
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        Translator::reset();
        parent::tearDown();
    }

    public function testTheListShowsEveryEmailAndWhereItIsEdited(): void
    {
        $r = $this->api('GET', '/emails');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $data = $this->json($r)['data'];
        $this->assertSame('en', $data['site_language']);
        $this->assertSame(['en', 'sr'], array_column($data['languages'], 'code'));
        $reset = array_values(array_filter($data['emails'], static fn(array $e): bool => $e['id'] === 'auth.password_reset'))[0];
        $this->assertSame(['id' => 'auth.password_reset', 'label' => 'Password reset', 'group' => 'Accounts', 'description' => 'Sent when someone asks to reset their password.', 'owner' => 'core', 'edited' => []], $reset);

        $this->assertSame(200, $this->api('PUT', '/emails/auth.password_reset', self::EDIT + ['locale' => 'sr'])->statusCode());
        $edited = array_column($this->json($this->api('GET', '/emails'))['data']['emails'], 'edited', 'id');
        $this->assertSame(['sr'], $edited['auth.password_reset']);
    }

    public function testOneEmailComesWithWhatItsCodeWritesAndAnEditToStartFrom(): void
    {
        $data = $this->json($this->api('GET', '/emails/auth.password_reset?locale=sr'))['data'];
        $this->assertSame('sr', $data['locale']);
        $this->assertSame(['link', 'safety'], $data['locked']);
        $this->assertSame(['site', 'name', 'link', 'minutes'], array_column($data['placeholders'], 'name'));
        $this->assertTrue($data['editable']);
        $this->assertSame('Nova lozinka za Kestello', $data['original']['subject'], 'in the language asked for, with sample values');
        $this->assertSame('Nova lozinka za {site}', $data['template']['subject'], 'the template shows where each value goes');
        $this->assertNull($data['edit']);

        $this->assertSame(404, $this->api('GET', '/emails/auth.nothing')->statusCode());
        $this->assertSame(422, $this->api('GET', '/emails/auth.password_reset?locale=fr')->statusCode(), 'only the site\'s languages');
    }

    public function testAnEditIsSavedForOneLanguageAndUsedWhenSendingInIt(): void
    {
        $r = $this->api('PUT', '/emails/auth.password_reset', self::EDIT + ['locale' => 'sr']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(self::EDIT['blocks'], $this->json($r)['data']['edit']['blocks']);
        $this->assertContains('email.edited', $this->auditEvents());

        $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'locale' => 'sr']);
        $this->mail = [];
        $resets = $this->cms->container->make(PasswordResets::class);
        $resets->request('bo@example.test', '203.0.113.1');
        $resets->request('ana@example.test', '203.0.113.1');
        $this->assertSame('A new password for Studio Ana', $this->mail[0]['subject'], 'bo reads Serbian, which has the edit');
        $this->assertStringContainsString('Hello bo', $this->mail[0]['text']);
        $this->assertMatchesRegularExpression('#>Choose a password</a>#', $this->mail[0]['html']);
        $this->assertStringContainsString('https://studio.test/reset-password?token=', $this->mail[0]['text'], 'the link is still the one the site made');
        $this->assertSame('Reset your Studio Ana password', $this->mail[1]['subject'], 'ana reads English, which has no edit');

        $r = $this->api('DELETE', '/emails/auth.password_reset?locale=sr');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertNull($this->json($r)['data']['edit']);
        $this->assertContains('email.reset', $this->auditEvents());
        $resets->request('bo@example.test', '203.0.113.2');
        $this->assertSame('Nova lozinka za Studio Ana', end($this->mail)['subject'], 'undone: back to what the code writes');
    }

    public function testAnEditThatBreaksTheEmailIsRefused(): void
    {
        $r = $this->api('PUT', '/emails/auth.password_reset', ['blocks' => [['type' => 'paragraph', 'text' => 'No link here']]]);
        $this->assertSame(422, $r->statusCode());
        $errors = $this->json($r)['errors'] ?? $this->json($r)['error']['fields'] ?? [];
        $this->assertContains('Keep the block “link”: the email doesn’t work without it.', $errors['blocks'] ?? []);
        $this->assertNull((new EmailEdits())->find('auth.password_reset', 'en'), 'nothing saved');
        $this->assertSame(422, $this->api('PUT', '/emails/auth.password_reset', ['blocks' => 'x'])->statusCode());
    }

    public function testASavedEditThatNoLongerFitsIsLeftOutSoTheEmailStillGoes(): void
    {
        (new EmailEdits())->save('auth.password_reset', 'en', ['subject' => 'Broken', 'blocks' => [['type' => 'paragraph', 'text' => 'No link']]], null);
        $this->mail = [];
        $this->cms->container->make(PasswordResets::class)->request('ana@example.test', '203.0.113.1');
        $this->assertSame('Reset your Studio Ana password', $this->mail[0]['subject']);
        $this->assertStringContainsString('reset-password?token=', $this->mail[0]['text']);
    }

    public function testADraftIsPreviewedAndSentToYouWithoutBeingSaved(): void
    {
        $r = $this->api('POST', '/emails/auth.password_reset/preview', self::EDIT);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $preview = $this->json($r)['data'];
        $this->assertSame('A new password for Kestello', $preview['subject'], 'sample values');
        $this->assertStringContainsString('Hello ana', $preview['text']);
        $this->assertStringContainsString('<html lang="en"', $preview['html']);
        $this->assertSame('Reset your Kestello password', $this->json($this->api('POST', '/emails/auth.password_reset/preview', ['locale' => 'en']))['data']['subject'], 'no draft: what is saved, here nothing');
        $this->assertSame(422, $this->api('POST', '/emails/auth.password_reset/preview', ['blocks' => []])->statusCode());

        $this->mail = [];
        $r = $this->api('POST', '/emails/auth.password_reset/test', self::EDIT + ['locale' => 'sr']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(['sent' => true, 'to' => 'ana@example.test'], $this->json($r)['data']);
        $this->assertSame('ana@example.test', $this->mail[0]['to'], 'only to your own address');
        $this->assertSame('[Test] A new password for Kestello', $this->mail[0]['subject']);
        $this->assertContains('email.test_sent', $this->auditEvents());
        $this->assertNull((new EmailEdits())->find('auth.password_reset', 'sr'), 'nothing saved');
    }

    public function testOnlyPeopleWhoManageEmailsCanUseIt(): void
    {
        $this->api('DELETE', '/auth/session');
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/emails')->statusCode());
        $this->assertSame(403, $this->api('PUT', '/emails/auth.password_reset', self::EDIT)->statusCode());
    }
}
