<?php

declare(strict_types=1);

namespace Kestello\Tests\Mail;

use Kestello\Cms\Forms\SendFormNotification;
use Kestello\Cms\Mail\CoreEmails;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Mail\MailLayout;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Translator;

final class MailLayoutTest extends TestCase
{
    private static function every(): MailMessage
    {
        return MailMessage::make('Order <1042>')
            ->heading('Your order <b>')
            ->paragraph("It left today.\nTracking follows.")
            ->rows(['Order' => '1042', 'Carrier' => 'Post & <Parcel>', '' => 'dropped'])
            ->button('Track it', 'https://shop.test/t/1?a=1&b=2')
            ->code('ABCD-1234')
            ->button('Second', 'https://shop.test/other')
            ->note('Questions? Reply to this email.');
    }

    public function testBlocksKeepTheirOrderInBothParts(): void
    {
        $m = self::every();
        $this->assertSame(['heading', 'paragraph', 'rows', 'button', 'code', 'button', 'note'], array_column($m->blocks(), 'type'));
        $this->assertCount(2, $m->blocks()[2]['rows'], 'a row without a label is left out');

        $this->assertSame(
            "Your order <b>\n\nIt left today.\nTracking follows.\n\nOrder: 1042\nCarrier: Post & <Parcel>\n\nTrack it\nhttps://shop.test/t/1?a=1&b=2\n\nABCD-1234\n\nSecond\nhttps://shop.test/other\n\nQuestions? Reply to this email.\n\nAcme",
            $m->text('Acme'),
        );

        $html = $m->render('Acme');
        $at = static fn(string $needle): int => (int) strpos($html, $needle);
        $this->assertTrue($at('Your order &lt;b&gt;') < $at('It left today.<br>') && $at('It left today.') < $at('>Order</th>')
            && $at('>Order</th>') < $at('>Track it</a>') && $at('>Track it</a>') < $at('ABCD-1234') && $at('ABCD-1234') < $at('>Second</a>')
            && $at('>Second</a>') < $at('Questions? Reply'), 'the HTML says the same things in the same order');
    }

    public function testEverythingIsEscaped(): void
    {
        $html = self::every()->render('Acme <Co>');
        $this->assertStringContainsString('<title>Order &lt;1042&gt;</title>', $html);
        $this->assertStringContainsString('Post &amp; &lt;Parcel&gt;', $html);
        $this->assertStringContainsString('href="https://shop.test/t/1?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('>Acme &lt;Co&gt;</td>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertSame(0, preg_match('/<script|onerror|javascript:/i', $html));
    }

    public function testTheFirstButtonIsWrittenOutOnceAtTheEnd(): void
    {
        $html = self::every()->render();
        $this->assertSame(1, substr_count($html, 'If the button does not work'));
        $this->assertMatchesRegularExpression('#copy this link into your browser:<br>https://shop\.test/t/1\?a=1&amp;b=2</p></td>#', $html);
        $this->assertStringNotContainsString('If the button does not work', MailMessage::make('x')->paragraph('No link here.')->render());
    }

    public function testTheLayoutIsTablesForMailClients(): void
    {
        $html = MailMessage::make('Hi')->paragraph('Hello')->render();
        $this->assertStringStartsWith('<!DOCTYPE html><html lang="en" dir="ltr"><head><meta charset="utf-8">', $html);
        $this->assertStringContainsString('<table role="presentation" width="100%"', $html);
        $this->assertStringContainsString('max-width:560px', $html);
        $this->assertStringEndsWith('</td></tr></table></body></html>', $html);
        $this->assertStringNotContainsString('<div', $html, 'no divs: Outlook ignores their widths');
        Translator::withLocale('ar', function (): void {
            $this->assertStringContainsString('<html lang="ar" dir="rtl">', MailMessage::make('x')->paragraph('y')->render(), 'right-to-left languages turn the layout');
        });
    }

    public function testTheBrandColoursTheEmail(): void
    {
        $brand = new MailBrand('Studio', ['page' => '#101010', 'accent' => '#0f766e', 'accentInk' => '#fafafa'], 'Georgia, serif');
        $html = MailMessage::make('x')->paragraph('y')->button('Go', 'https://a.test')->render($brand);
        $this->assertStringContainsString('background:#101010', $html);
        $this->assertStringContainsString('background:#0f766e;color:#fafafa', $html);
        $this->assertStringContainsString('font-family:Georgia, serif', $html);
        $this->assertStringContainsString('background:' . MailBrand::DEFAULT_COLORS['card'], $html, 'roles left out keep the default');
        $this->assertSame('#0f766e', $brand->color('accent'));
        $this->assertSame('Kestello', MailBrand::named('  ')->name);
    }

    public function testButtonsAndTextStayReadable(): void
    {
        $pale = new MailBrand('x', ['accent' => '#fde047', 'accentInk' => '#ffffff']);
        $this->assertSame(['#111827', '#ffffff'], [$pale->color('accent'), $pale->color('accentInk')], 'a white label on yellow becomes ink on card');

        $faint = new MailBrand('x', ['ink' => '#dddddd', 'muted' => '#eeeeee']);
        $this->assertSame(MailBrand::DEFAULT_COLORS['ink'], $faint->color('ink'), 'text too faint on its card falls back to the defaults');
        $this->assertGreaterThanOrEqual(4.5, MailBrand::ratio($faint->color('muted'), $faint->color('card')));

        $teal = new MailBrand('x', ['accent' => '#0f766e', 'accentInk' => '#ffffff']);
        $this->assertSame(['#0f766e', '#ffffff'], [$teal->darkColor('accent'), $teal->darkColor('accentInk')], 'a button that shows on the dark card stays in dark mode');
        $navy = new MailBrand('x', ['accent' => '#1e3a8a', 'accentInk' => '#ffffff']);
        $this->assertSame([MailBrand::DEFAULT_DARK['ink'], MailBrand::DEFAULT_DARK['card']], [$navy->darkColor('accent'), $navy->darkColor('accentInk')], 'one that would vanish turns light on dark');
        $own = new MailBrand('x', [], MailBrand::DEFAULT_FONT, ['accent' => '#93c5fd', 'accentInk' => '#0b1220']);
        $this->assertSame('#93c5fd', $own->darkColor('accent'), "the theme's own dark button wins");

        foreach ([MailBrand::named('x'), $pale, $teal, $navy, $own] as $brand) {
            foreach (['color', 'darkColor'] as $scheme) {
                $c = $brand->{$scheme}(...);
                $this->assertGreaterThanOrEqual(4.5, MailBrand::ratio($c('ink'), $c('card')));
                $this->assertGreaterThanOrEqual(4.5, MailBrand::ratio($c('accentInk'), $c('accent')));
            }
        }
    }

    public function testDarkModeLogoAndFooter(): void
    {
        $brand = new MailBrand('Bakery <Ana>', logo: ['url' => 'https://bakery.test/media/logo.png?v=1&s=2', 'width' => 300, 'height' => 100], footer: "Main street 1\nNovi Sad");
        $m = MailMessage::make('Hi')->heading('Hello')->paragraph('Text')->rows(['A' => 'b'])->code('123')->note('Small')->button('Go', 'https://a.test');
        $html = $m->render($brand);

        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){.k-page{background:' . MailBrand::DEFAULT_DARK['page'] . '!important}', $html);
        foreach (['k-page', 'k-card', 'k-ink', 'k-muted', 'k-row', 'k-code', 'k-btn'] as $class) {
            $this->assertMatchesRegularExpression('/class="' . $class . '"/', $html, "{$class} is used, so its dark colour applies");
        }
        $this->assertStringContainsString('<img class="k-logo" src="https://bakery.test/media/logo.png?v=1&amp;s=2" alt="Bakery &lt;Ana&gt;" width="120" height="40"', $html, 'the logo, 40 pixels tall, named for the site');
        $this->assertStringNotContainsString('k-logo-dark', $html, 'no logo for dark mode: nothing to swap');
        $this->assertStringContainsString('>Bakery &lt;Ana&gt;<br>Main street 1<br>Novi Sad</td>', $html);
        $this->assertStringEndsWith("\n\nBakery <Ana>\nMain street 1\nNovi Sad", $m->text($brand), 'the footer ends the text part too');

        $this->assertStringNotContainsString('<img', MailMessage::make('x')->paragraph('y')->render(), 'no logo, no image');
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MailBrand('x', logo: ['url' => '/media/logo.png', 'width' => 10, 'height' => 10]), 'whole http(s) address');
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MailBrand('x', darkLogo: ['url' => 'https://a.test/l.png', 'width' => 10, 'height' => 10]), 'a logo for dark mode replaces a logo');
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MailBrand('x', logo: ['url' => 'https://a.test/l.png', 'width' => 10, 'height' => 10], darkLogo: ['url' => 'javascript:x', 'width' => 10, 'height' => 10]), 'whole http(s) address for it too');
    }

    public function testBadInputIsRefusedWithAClearMessage(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn() => MailMessage::make('x')->button('Go', 'javascript:alert(1)'), 'http(s)');
        $this->assertFalse(MailMessage::isLink('/admin'));
        $this->assertFalse(MailMessage::isLink('//evil.test'));
        $this->assertTrue(MailMessage::isLink('https://a.test/x'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MailBrand('x', ['accent' => 'red']), '#rrggbb');
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MailBrand('x', ['button' => '#000000']), 'Known: page, card');
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MailBrand('x', [], 'x;}</style>'), 'font stack');
        $this->assertThrows(\InvalidArgumentException::class, fn() => MailLayout::text([['type' => 'video']], MailBrand::named('x')), 'Unknown email block "video"');
    }

    public function testTheOlderNamesStillWork(): void
    {
        $old = MailMessage::make('s')->greeting('H')->line('A')->action('Go', 'https://a.test')->line('B');
        $this->assertSame(['heading', 'paragraph', 'button', 'paragraph'], array_column($old->blocks(), 'type'));
        $this->assertSame("H\n\nA\n\nGo\nhttps://a.test\n\nB\n\nKestello", $old->text());
        $custom = MailMessage::make('s')->paragraph('ignored')->html('<p>Own <a href="https://a.test">link</a></p>');
        $this->assertSame('<p>Own <a href="https://a.test">link</a></p>', $custom->render());
        $this->assertStringContainsString('link (https://a.test)', $custom->text());
    }

    public function testFormEmailsAreBlocksToo(): void
    {
        $form = ['id' => 7, 'title' => 'Contact', 'definition' => ['fields' => [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'options' => []],
            ['name' => 'ok', 'label' => 'Consent', 'type' => 'boolean', 'options' => []],
            ['name' => 'skip', 'label' => 'Not sent', 'type' => 'text', 'options' => []],
        ]]];
        $m = CoreEmails::formSubmission(SendFormNotification::data('New: Contact', $form, ['name' => "Ana\nMarić", 'ok' => true], 'Bakery', 'https://bakery.test', 12, '/contact'));
        $this->assertSame(['heading', 'paragraph', 'rows', 'button'], array_column($m->blocks(), 'type'));
        $this->assertSame([['label' => 'Name', 'value' => "Ana\nMarić"], ['label' => 'Consent', 'value' => 'Yes']], $m->blocks()[2]['rows']);
        $this->assertSame('https://bakery.test/admin/forms/7/submissions?open=12', $m->blocks()[3]['url']);
        $this->assertStringContainsString('Someone sent Contact on Bakery, from /contact.', $m->text('Bakery'));

        $noBase = CoreEmails::formSubmission(SendFormNotification::data('New: Contact', $form, ['name' => 'Ana'], 'Bakery', '', 12, ''));
        $this->assertNotContains('button', array_column($noBase->blocks(), 'type'), 'no site address, no button to the admin');
    }

    public function testTheTestEmailIsTranslated(): void
    {
        Translator::add('core', 'sr', ['Test email from {site}' => 'Probna poruka sa sajta {site}', 'Your email settings work' => 'Podešavanja e-pošte rade']);
        Translator::withLocale('sr', function (): void {
            $m = CoreEmails::testEmail(['site' => 'Pekara']);
            $this->assertSame('Probna poruka sa sajta Pekara', $m->subject);
            $this->assertStringContainsString('>Podešavanja e-pošte rade</h1>', $m->render('Pekara'));
        });
    }
}
