<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Auth\EmailChanges;
use Kestello\Cms\Auth\LoginThrottle;
use Kestello\Cms\Auth\PasswordResets;
use Kestello\Cms\Auth\Registrations;
use Kestello\Cms\Events\IdentityLinked;
use Kestello\Cms\Events\LoginFailed;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/**
 * The emails core sends about accounts: in the site's language, in the
 * notification layout, and with a plain-text part.
 */
final class AccountEmailsTest extends AuthTestCase
{
    private const string BASE = 'https://studio.test';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::getInstance()->set('site_name', 'Studio Ana');
        Settings::getInstance()->set('site_url', self::BASE);
        $this->bootCms();    // the auth services read the site's name and address when they are made
        $this->cms->config->set('auth.registration', 'open');
    }

    /**
     * Every account email, in the order sent: the user they were for is
     * "ana" (ana@example.test).
     *
     * @return list<array{to: string, subject: string, html: string, text: string}>
     */
    private function sendEveryEmail(): array
    {
        $ana = $this->createUser();
        $id = (int) $ana['id'];
        $this->mail = [];

        $this->cms->container->make(Registrations::class)->request('new@example.test', '203.0.113.1');
        $this->cms->container->make(Registrations::class)->request('ana@example.test', '203.0.113.1');
        $this->cms->container->make(PasswordResets::class)->request('ana@example.test', '203.0.113.1');
        $this->assertNull($this->cms->container->make(EmailChanges::class)->request($ana, 'ana@new.test'));
        $this->cms->events->dispatch(new IdentityLinked($id, 'github', 1, 'profile'));
        $throttle = $this->cms->container->make(LoginThrottle::class);
        for ($i = 1; $i <= AuthModule::SECOND_FACTOR_WARNING; $i++) {
            $throttle->reserve('2fa', "198.51.100.{$i}", 'user:' . $id);
        }
        $this->cms->events->dispatch(new LoginFailed('ana', $id, 'two_factor', '198.51.100.9'));

        $this->assertCount(7, $this->mail, 'seven account emails went out');

        return $this->mail;
    }

    public function testEveryAccountEmailIsWrittenInTheSiteLanguage(): void
    {
        Translator::add('core', 'sr', [
            'Finish creating your {site} account' => 'Završite nalog na sajtu {site}',
            'You already have a {site} account' => 'Već imate nalog na sajtu {site}',
            'Reset your {site} password' => 'Nova lozinka za {site}',
            'Confirm your new {site} email' => 'Potvrdite novu adresu za {site}',
            'Your {site} email is being changed' => 'Adresa za {site} se menja',
            'New sign-in method on {site}' => 'Nov način prijave na {site}',
            'Someone has your {site} password' => 'Neko zna vašu lozinku za {site}',
            'Hello,' => 'Zdravo,',
            'Hi {name},' => 'Zdravo, {name},',
            'Finish creating my account' => 'Završi nalog',
            'Sign in' => 'Prijavi se',
            'Reset my password' => 'Nova lozinka',
            'Use this email address' => 'Koristi ovu adresu',
            '{hours, plural, one {The link works once and expires in # hour.} other {The link works once and expires in # hours.}}' => '{hours, plural, one {Veza važi jednom, # sat.} few {Veza važi jednom, # sata.} other {Veza važi jednom, # sati.}}',
            '{minutes, plural, one {The link works once and expires in # minute.} other {The link works once and expires in # minutes.}}' => '{minutes, plural, one {Veza važi jednom, # minut.} few {Veza važi jednom, # minuta.} other {Veza važi jednom, # minuta.}}',
            'If you forgot your password, reset it here: {url}' => 'Ako ste zaboravili lozinku: {url}',
            'Someone signed in to your {site} account asked to change its email address to {email}. Nothing changes until that address confirms it.' => 'Traži se promena adrese na {email} za {site}.',
            'A {provider} account was connected to your {site} account (“{label}”). It can now be used to sign in.' => 'Nalog {provider} („{label}”) je povezan sa {site}.',
        ]);

        $mail = Translator::withLocale('sr', fn(): array => $this->sendEveryEmail());

        $this->assertSame([
            'Završite nalog na sajtu Studio Ana',
            'Već imate nalog na sajtu Studio Ana',
            'Nova lozinka za Studio Ana',
            'Potvrdite novu adresu za Studio Ana',
            'Adresa za Studio Ana se menja',
            'Nov način prijave na Studio Ana',
            'Neko zna vašu lozinku za Studio Ana',
        ], array_column($mail, 'subject'), 'subjects come from the translator, with the site name in place');

        foreach ($mail as $m) {
            $this->assertStringContainsString('<html lang="sr"', $m['html'], "{$m['subject']}: the email says its language");
            $this->assertStringStartsWith('Zdravo,', $m['text'], "{$m['subject']}: the greeting is translated");
        }
        $this->assertStringContainsString('>Završi nalog</a>', $mail[0]['html'], 'the button label is translated');
        $this->assertStringContainsString('Veza važi jednom, 24 sata.', $mail[0]['text'], 'the link lifetime is a translated plural');
        $this->assertStringContainsString('Ako ste zaboravili lozinku: ' . self::BASE . '/forgot-password', $mail[1]['text']);
        $this->assertStringContainsString('>Nova lozinka</a>', $mail[2]['html']);
        $this->assertStringContainsString('Veza važi jednom, 60 minuta.', $mail[2]['text']);
        $this->assertStringContainsString('>Koristi ovu adresu</a>', $mail[3]['html']);
        $this->assertStringContainsString('Traži se promena adrese na ana@new.test za Studio Ana.', $mail[4]['text']);
        $this->assertStringContainsString('Nalog github („github”) je povezan sa Studio Ana.', $mail[5]['text']);
    }

    public function testTheAccountEmailsUseTheNotificationLayout(): void
    {
        $mail = $this->sendEveryEmail();
        $buttons = [
            0 => ['Finish creating my account', '#^https://studio\.test/register/complete\?token=[A-Za-z0-9_-]{43}$#'],
            1 => ['Sign in', '#^https://studio\.test/login$#'],
            2 => ['Reset my password', '#^https://studio\.test/reset-password\?token=[A-Za-z0-9_-]{43}$#'],
            3 => ['Use this email address', '#^https://studio\.test/confirm-email\?token=[A-Za-z0-9_-]{43}$#'],
        ];

        foreach ($mail as $i => $m) {
            $this->assertStringStartsWith('<!DOCTYPE html><html lang="en" dir="ltr">', $m['html'], "{$m['subject']}: a whole document, not a fragment");
            $this->assertStringContainsString('<title>' . htmlspecialchars($m['subject']) . '</title>', $m['html']);
            $this->assertMatchesRegularExpression('#text-align:center">Studio Ana</td></tr></table></td></tr></table></body></html>$#', $m['html'], "{$m['subject']}: the site name is in the footer");
            $this->assertStringEndsWith("\n\nStudio Ana", $m['text'], "{$m['subject']}: the text part ends with the site name");
            $this->assertStringNotContainsString('<', $m['text'], "{$m['subject']}: the text part has no markup");
            $this->assertStringNotContainsString('—', $m['html'] . $m['text']);
            $this->assertStringNotContainsString('–', $m['html'] . $m['text']);

            if (!isset($buttons[$i])) {
                $this->assertStringNotContainsString('display:inline-block', $m['html'], "{$m['subject']}: no button where there is no link");
                continue;
            }
            [$label, $url] = $buttons[$i];
            $this->assertSame(1, preg_match('#<a class="k-btn" href="([^"]+)" style="display:inline-block[^"]*">' . preg_quote($label, '#') . '</a>#', $m['html'], $button), "{$m['subject']}: the link is the button");
            $this->assertMatchesRegularExpression($url, html_entity_decode($button[1]));
            $this->assertStringContainsString('If the button does not work, copy this link into your browser:<br>' . $button[1], $m['html'], "{$m['subject']}: the link is written out under the button");
            $this->assertStringContainsString($label . "\n" . html_entity_decode($button[1]) . "\n\n", $m['text'], "{$m['subject']}: the text part has the link under its label");
        }

        $this->assertStringStartsWith("Hello,\n\nSomeone asked to create a Studio Ana account with this email address. If it was you, choose your password with the button below.\n\n", $mail[0]['text']);
        $this->assertStringContainsString('The link works once and expires in 24 hours.', $mail[0]['text']);
        $this->assertStringContainsString('If you didn’t ask for this, ignore this email: no account is created.', $mail[0]['text']);
        $this->assertStringContainsString('The link works once and expires in 60 minutes.', $mail[2]['text']);
        $this->assertStringContainsString('The link works once and expires in 24 hours.', $mail[3]['text']);
        $this->assertStringContainsString('ana@new.test', $mail[4]['text'], 'the old address is told the new one');
        $this->assertStringNotContainsString('confirm-email', $mail[4]['html'], 'and gets no link');
    }
}
