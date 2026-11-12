<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Clock;
use Kestello\Support\Color;
use Kestello\Support\Date;
use Kestello\Support\I18n\PluralRule;
use Kestello\Support\Markdown;
use Kestello\Support\Str;
use Kestello\Support\Translator;

use function Kestello\__;
use function Kestello\trans_choice;

/** H2: Color, Markdown, Translator. */
final class TextHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::reset();
        Clock::unfreeze();
    }

    public function testColor(): void
    {
        $accent = Color::parse('#6676D8');
        $this->assertSame('#6676d8', $accent->hex());
        $this->assertSame('#6676d8', Color::parse('rgb(102 118 216)')->hex());
        $this->assertSame('#6676d8', Color::parse('rgba(102, 118, 216, 1)')->hex());
        $this->assertSame('#ff0000', Color::parse('hsl(0 100% 50%)')->hex());
        $this->assertSame('#6676d8', Color::parse($accent->oklchString())->hex(), 'OKLCH round trip');
        $this->assertSame('#11223380', Color::parse('#11223380')->hex());
        $this->assertSame('#aabbcc', Color::parse('abc')->hex());
        $this->assertFalse(Color::isValid('#12'));
        $this->assertFalse(Color::isValid('rgb(1,2)'));

        $white = Color::parse('#fff');
        $this->assertSame(21.0, Color::parse('#000')->contrast($white));
        $this->assertSame(4.48, Color::parse('#777')->contrast($white), 'WCAG reference value');
        $this->assertFalse(Color::parse('#777')->meetsWcag($white));
        $this->assertTrue(Color::parse('#777')->meetsWcag($white, 'AA', largeText: true));
        $this->assertSame('#ffffff', Color::parse('#111827')->readableText()->hex());
        $this->assertSame('#111827', Color::parse('#f8f7f3')->readableText()->hex());

        $this->assertTrue($accent->lighten(0.1)->luminance() > $accent->luminance());
        $this->assertSame('#636363', Color::parse('#000')->mix($white)->hex(), 'perceptual midpoint');
        $palette = $accent->palette();
        $this->assertSame(['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'], array_map('strval', array_keys($palette)));
        $lum = array_map(static fn(Color $c) => $c->luminance(), array_values($palette));
        $sorted = $lum;
        rsort($sorted);
        $this->assertSame($sorted, $lum, 'palette goes from light to dark');
        $this->assertTrue($palette['700']->meetsWcag($white), 'dark shades work as text on white');
    }

    public function testMarkdown(): void
    {
        $this->assertSame('<h2>Title <em>x</em></h2>', Markdown::toHtml('## Title *x* ##'));
        $this->assertSame('<p><strong>b</strong> <em>i</em> <del>s</del> <code>&lt;b&gt;</code> snake_case_word</p>', Markdown::toHtml('**b** _i_ ~~s~~ `<b>` snake_case_word'));
        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', Markdown::toHtml('<script>alert(1)</script>'), 'raw HTML is escaped');
        $this->assertSame('<p><a>x</a></p>', Markdown::toHtml('[x](javascript:alert(1))'), 'unsafe URL dropped by the sanitizer');
        $this->assertContains('<a href="https://kestello.dev" title="T">site</a>', Markdown::toHtml("[site][k]\n\n[k]: https://kestello.dev \"T\""));
        $this->assertContains('<a href="https://example.com/a?b=1">https://example.com/a?b=1</a>.', Markdown::toHtml('See https://example.com/a?b=1.'));
        $this->assertSame('<p>*not em*</p>', Markdown::toHtml('\*not em\*'));
        $this->assertSame("<p>a<br>\nb</p>", Markdown::toHtml("a  \nb"));

        $list = Markdown::toHtml("- a\n- b\n  - c\n- [x] done\n\n1. one\n2. two");
        $this->assertSame("<ul>\n<li>a</li>\n<li>b\n<ul>\n<li>c</li>\n</ul></li>\n<li class=\"task-list-item\"><span class=\"task-checkbox is-done\">☑</span> done</li>\n</ul>\n<ol>\n<li>one</li>\n<li>two</li>\n</ol>", $list);
        $this->assertContains('<ol start="3">', Markdown::toHtml('3. three'));
        $this->assertContains('<li><p>a</p></li>', Markdown::toHtml("- a\n\n- b"), 'loose list');

        $this->assertSame("<pre><code class=\"language-php\">&lt;?php echo 1;\n</code></pre>", Markdown::toHtml("```php\n<?php echo 1;\n```"));
        $this->assertSame("<pre><code>x = 1\n</code></pre>", Markdown::toHtml('    x = 1'));
        $this->assertSame("<blockquote>\n<p>q <em>e</em></p>\n</blockquote>", Markdown::toHtml('> q *e*'));
        $this->assertSame('<h1>Set</h1>' . "\n" . '<hr>', Markdown::toHtml("Set\n===\n\n***"));
        $this->assertSame('<table><thead><tr><th>A</th><th class="text-right">B</th></tr></thead><tbody><tr><td>a|b</td><td class="text-right">2</td></tr></tbody></table>', Markdown::toHtml("| A | B |\n|---|--:|\n| a\\|b | 2 |"));
        $this->assertSame('<p><img src="https://x.test/i.png" alt="alt text"></p>', Markdown::toHtml('![alt *text*](https://x.test/i.png)'));
        $this->assertSame('<p><strong>b</strong></p>', Markdown::toHtml('**b**', 'basic'));
        $basic = Str::markdown('# b', 'basic');
        $this->assertNotContains('<h1', $basic, 'basic profile drops headings');
        $this->assertContains('b', $basic);
    }

    public function testTranslator(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/sr.json", json_encode(['Save' => 'Sačuvaj', 'Hello, {name}!' => 'Zdravo, {name}!']));
        file_put_contents("{$dir}/sr_Latn_RS.php", "<?php return ['Save' => 'Snimi'];");
        file_put_contents("{$dir}/sr.po", <<<'PO'
            msgid ""
            msgstr ""
            "Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n"

            msgid "file"
            msgid_plural "files"
            msgstr[0] "{count} fajl"
            msgstr[1] "{count} fajla"
            msgstr[2] "{count} fajlova"

            msgctxt "verb"
            msgid "Post"
            msgstr "Objavi"

            #, fuzzy
            msgid "Draft"
            msgstr "Nacrt?"
            PO);
        Translator::addPath('acme', $dir);

        $this->assertSame('Save', __('Save', domain: 'acme'), 'English: the key');
        Translator::setLocale('sr-latn-rs');
        $this->assertSame('sr_Latn_RS', Translator::locale());
        $this->assertSame('Snimi', __('Save', domain: 'acme'), 'region file wins');
        $this->assertSame('Zdravo, Ana!', __('Hello, {name}!', ['name' => 'Ana'], 'acme'), 'falls back to the language');
        $this->assertSame('Missing {x}', __('Missing {x}', domain: 'acme'));
        $this->assertSame('Missing 1', __('Missing {x}', ['x' => 1], 'acme'));
        $this->assertSame('Objavi', Translator::get('Post', domain: 'acme', context: 'verb'));
        $this->assertSame('Draft', __('Draft', domain: 'acme'), 'fuzzy entries are skipped');
        foreach ([1 => '1 fajl', 3 => '3 fajla', 5 => '5 fajlova', 11 => '11 fajlova', 21 => '21 fajl', 22 => '22 fajla'] as $n => $expected) {
            $this->assertSame($expected, trans_choice('file', $n, domain: 'acme'), "{$n}");
        }
        $this->assertSame('Save', Translator::withLocale('en', fn() => __('Save', domain: 'acme')));
        $this->assertSame('sr_Latn_RS', Translator::locale());

        Translator::add('core', 'sr', ['{count, plural, one {# comment} other {# comments}}' => '{count, plural, one {# komentar} few {# komentara} other {# komentara}}']);
        $this->assertSame('5 komentara', trans_choice('{count, plural, one {# comment} other {# comments}}', 5));
        $this->assertSame('1 comment', trans_choice('{count, plural, one {# comment} other {# comments}}', 1, locale: 'en'), 'ICU in the key itself');

        Clock::freeze('2026-11-04 12:00:00');
        Translator::add('core', 'sr', ['{count, plural, one {# minute ago} other {# minutes ago}}' => 'pre {count, plural, one {# minuta} few {# minuta} other {# minuta}}']);
        $this->assertSame('pre 5 minuta', Date::parse('2026-11-04 11:55:00')->diffForHumans());
        $this->assertSame('in 2 days', Date::parse('2026-11-06 12:00:00')->diffForHumans(), 'untranslated: English');
        Translator::setLocale('en');
        $this->assertSame('5 minutes ago', Date::parse('2026-11-04 11:55:00')->diffForHumans());

        $this->assertTrue(Translator::isRtl('ar_EG'));
        $this->assertSame('rtl', Translator::direction('he'));
        $this->assertSame('ltr', Translator::direction('sr'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Translator::setLocale('../../etc'));

        $this->assertSame(2, (new PluralRule('n==1 ? 0 : n>=2 && n<=4 ? 1 : 2'))->index(7));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new PluralRule('system("x")'));
    }
}
