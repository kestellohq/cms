<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\EmailChanges;
use Kestello\Cms\Auth\PasswordResets;
use Kestello\Cms\Auth\Registrations;
use Kestello\Cms\I18n\PersonLanguage;
use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/** Each person's language: chosen on their profile, kept with the account, used for their emails. */
final class PersonLanguageTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pack = $this->tempDir();
        file_put_contents("{$pack}/sr.json", json_encode([
            'Reset your {site} password' => 'Nova lozinka za {site}',
            'Confirm your new {site} email' => 'Potvrdite novu adresu za {site}',
            'Finish creating your {site} account' => 'Završite nalog na {site}',
        ]));
        Translator::addPath('core', $pack);
        Settings::getInstance()->set('site_name', 'Studio Ana');
        Settings::getInstance()->set('site_url', 'https://studio.test');
        $this->bootCms();
        $this->cms->config->set('auth.registration', 'open');
    }

    protected function tearDown(): void
    {
        Translator::reset();
        parent::tearDown();
    }

    public function testPeopleChooseOneOfTheSitesLanguages(): void
    {
        $this->assertSame(['', 'en', 'sr'], PersonLanguage::allowed(), "'' is the site's language, then each installed one");
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $me = $this->json($this->api('GET', '/me'))['data'];
        $this->assertSame('', $me['locale'], "the site's language until chosen");
        $this->assertSame(['en', 'sr'], array_column($me['languages'], 'code'), 'the choices, with their names');

        $r = $this->api('PATCH', '/me', ['locale' => 'sr']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame('sr', $this->json($r)['data']['locale']);
        $this->assertSame(422, $this->api('PATCH', '/me', ['locale' => 'fr'])->statusCode(), 'only languages the site has');
        $this->assertSame(422, $this->api('PATCH', '/me', ['locale' => '../etc'])->statusCode());
        $this->assertSame('', $this->json($this->api('PATCH', '/me', ['locale' => '']))['data']['locale'], 'and back to the site\'s');
        $this->assertContains('profile.updated', $this->auditEvents());
    }

    public function testEmailsGoOutInTheReadersLanguage(): void
    {
        $ana = $this->createUser(['locale' => 'sr']);
        $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'locale' => '']);
        $this->mail = [];

        $resets = $this->cms->container->make(PasswordResets::class);
        $resets->request('ana@example.test', '203.0.113.1');
        $resets->request('BO@example.test', '203.0.113.1');
        $this->assertSame('Nova lozinka za Studio Ana', $this->mail[0]['subject'], 'ana reads Serbian');
        $this->assertStringContainsString('<html lang="sr"', $this->mail[0]['html'], 'and the email says so');
        $this->assertSame('Reset your Studio Ana password', $this->mail[1]['subject'], "bo has the site's language");

        $this->assertNull($this->cms->container->make(EmailChanges::class)->request($ana, 'ana@new.test'));
        $this->assertSame('Potvrdite novu adresu za Studio Ana', $this->mail[2]['subject'], "the new address isn't hers yet, so her account's language is used");

        $this->cms->container->make(Registrations::class)->request('someone@new.test', '203.0.113.1');
        $this->assertSame('Finish creating your Studio Ana account', end($this->mail)['subject'], "no account: the page's language");
        Translator::withLocale('sr', fn() => $this->cms->container->make(Registrations::class)->request('drugi@new.test', '203.0.113.1'));
        $this->assertSame('Završite nalog na Studio Ana', end($this->mail)['subject'], 'a sign-up from a Serbian page gets Serbian');
        $this->assertSame('en', Translator::locale(), 'the language is put back after each email');
    }

    public function testANewAccountStartsInThePagesLanguageOnlyWhenItDiffers(): void
    {
        $this->assertSame(PersonLanguage::SITE, PersonLanguage::atSignUp(), "signing up on a page in the site's language follows the site");
        $this->assertSame('sr', Translator::withLocale('sr', PersonLanguage::atSignUp(...)));
        $this->assertSame(PersonLanguage::SITE, Translator::withLocale('de', PersonLanguage::atSignUp(...)), 'a language the site has no pack for is not kept');

        Settings::getInstance()->set(SiteLanguage::SETTING, 'sr');
        $this->assertSame(PersonLanguage::SITE, Translator::withLocale('sr', PersonLanguage::atSignUp(...)), 'Serbian is the site\'s own now');

        $this->assertNull(PersonLanguage::of(['locale' => 'fr']), 'a language whose pack is gone counts as the site\'s');
        $this->assertNull(PersonLanguage::forAddress('nobody@example.test'));
        $this->assertNull(PersonLanguage::forAddress('not an address'));
    }

    public function testTheLanguageIsInTheDataDownload(): void
    {
        $this->createUser(['locale' => 'sr']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $r = $this->api('GET', '/me/export');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame('sr', json_decode($r->content(), true)['account']['locale']);
    }
}
