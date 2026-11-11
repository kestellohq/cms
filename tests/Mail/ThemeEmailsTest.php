<?php

declare(strict_types=1);

namespace Kestello\Tests\Mail;

use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageChecker;
use Kestello\Cms\Extension\PackageState;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Mail\MailLayout;
use Kestello\Cms\Mail\ThemeEmail;
use Kestello\Cms\Mail\ThemeEmails;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Tests\Auth\AuthTestCase;

/** A theme's own email layout and per-email templates: what is checked, what is filled in, which file wins. */
final class ThemeEmailsTest extends AuthTestCase
{
    private const string LAYOUT = '<html lang="{{ lang }}"><body style="background:{{ brand.page }}"><h1>{{ site.name }}</h1>{!! logo !!}{!! blocks !!}<footer>{!! footer !!}</footer><style>{!! dark_css !!}</style></body></html>';

    private string $root;
    private ArrayStateStore $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        $this->state = new ArrayStateStore();
        ThemeEmails::forget();
    }

    protected function tearDown(): void
    {
        ThemeEmails::forget();
        parent::tearDown();
    }

    private function theme(string $slug, array $emails, ?string $parent = null): void
    {
        @mkdir("{$this->root}/content/themes/{$slug}/emails", 0777, true);
        $manifest = ['slug' => $slug, 'name' => ucfirst($slug), 'version' => '1.0.0', 'requires' => ['core' => '^0.1']] + ($parent !== null ? ['parent' => $parent] : []);
        file_put_contents("{$this->root}/content/themes/{$slug}/theme.json", json_encode($manifest));
        foreach ($emails as $name => $html) {
            file_put_contents("{$this->root}/content/themes/{$slug}/emails/{$name}.html", $html);
        }
    }

    /** The next request, with the themes as they are on disk. */
    private function boot(): ExtensionManager
    {
        $extensions = new ExtensionManager($this->cms, $this->state, new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);
        ThemeEmails::forget();

        return $extensions;
    }

    private static function message(): MailMessage
    {
        return MailMessage::make('Reset <yours>')->heading('Reset your password')->button('Choose a new password', 'https://site.test/reset?t=1')->name('button')->note('Not you? Ignore this.');
    }

    public function testALayoutPlacesTheBlocksAndWritesRawOnlyWhatKestelloBuilds(): void
    {
        $this->assertSame([], ThemeEmails::problems(self::LAYOUT));
        $this->assertSame(['a layout must place the email with {!! blocks !!}'], ThemeEmails::problems('<p>{{ site.name }}</p>'));
        $this->assertSame(['{!! site.name !!} would not be escaped: write {{ site.name }}'], ThemeEmails::problems('{!! site.name !!}{!! blocks !!}'));
        $this->assertSame(['there is no placeholder "site.owner"; known ones are ' . implode(', ', ThemeEmails::TEXT)], ThemeEmails::problems('{{ site.owner }}{!! blocks !!}'));
        $this->assertSame(['there is no placeholder "logo"; write {!! logo !!}'], ThemeEmails::problems('{{ logo }}{!! blocks !!}'));
        $this->assertSame(['{!! block.button !!} names one email\'s block; a layout places them all with {!! blocks !!}'], ThemeEmails::problems('{!! block.button !!}{!! blocks !!}'));
        $this->assertSame(['it is larger than 200 KB'], ThemeEmails::problems(str_repeat('x', ThemeEmails::MAX_BYTES + 1)));
    }

    public function testMarkupAnEmailCannotCarryIsRefused(): void
    {
        foreach ([
            '<script>alert(1)</script>' => 'scripts',
            '<form action="/x"></form>' => 'forms',
            '<iframe src="https://x.test"></iframe>' => 'embedded frames',
            '<img src="x" onerror="go()">' => 'event handlers',
            '<a href="javascript:go()">x</a>' => 'javascript: links',
            '<meta http-equiv="refresh" content="0;url=https://x.test">' => 'http-equiv',
        ] as $html => $why) {
            $problems = ThemeEmails::problems($html . '{!! blocks !!}');
            $this->assertCount(1, $problems, $html);
            $this->assertStringContainsString($why, $problems[0], $html);
        }
        $this->assertSame([], ThemeEmails::problems('<!-- notes on the layout --><p class="note">{!! blocks !!}</p>'), 'a comment or the word "on" is no handler');
    }

    public function testAnEmailsOwnFileKeepsTheBlocksItCannotWorkWithout(): void
    {
        $names = ['button'];
        $this->assertSame([], ThemeEmails::problems('<div>{!! block.button !!}</div>', $names, ['button']));
        $this->assertSame([], ThemeEmails::problems('<div>{!! blocks !!}</div>', $names, ['button']), 'all the blocks include it');
        $this->assertSame(
            ['it must show the block "button" ({!! block.button !!}) or all of them ({!! blocks !!}): the email does not work without it'],
            ThemeEmails::problems('<div>{{ subject }}</div>', $names, ['button']),
        );
        $this->assertSame(['the email has no block named "buton"; it has button'], ThemeEmails::problems('{!! block.buton !!}{!! blocks !!}', $names));
        $this->assertSame([], ThemeEmails::problems('{!! block.anything !!}', []), 'names are not known outside a site (plugin:check)');
    }

    public function testTheLayoutWrapsTheSameBlocksWithTheBrandFilledIn(): void
    {
        $brand = new MailBrand('Ana & <Sons>', ['page' => '#eeeeee'], footer: "1 Quay\nPort <town>");
        $html = MailLayout::html('Reset', self::message()->blocks(), $brand, new ThemeEmail(null, 'layout.html', self::LAYOUT));

        $this->assertStringStartsWith('<html lang="en"><body style="background:#eeeeee"><h1>Ana &amp; &lt;Sons&gt;</h1>', $html);
        $this->assertStringContainsString('href="https://site.test/reset?t=1"', $html);
        $this->assertStringContainsString('<footer>1 Quay<br>Port &lt;town&gt;</footer>', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('If the button does not work', $html, 'the written-out link comes with the blocks');
    }

    public function testAFileThatFailsItsCheckFallsBackToKestellosLayout(): void
    {
        $brand = MailBrand::named('Ana');
        $own = MailLayout::html('Reset', self::message()->blocks(), $brand);

        $this->assertSame($own, MailLayout::html('Reset', self::message()->blocks(), $brand, new ThemeEmail(null, 'layout.html', '<script></script>{!! blocks !!}')));
        $this->assertSame($own, MailLayout::html('Reset', self::message()->blocks(), $brand, new ThemeEmail('auth.password_reset', 'x.html', '<p>{{ subject }}</p>', ['button'])), 'an email file without its link');
        $kept = MailLayout::html('Reset', self::message()->blocks(), $brand, new ThemeEmail('auth.password_reset', 'x.html', '<b>{!! block.button !!}</b>', ['button']));
        $this->assertStringStartsWith('<b><table role="presentation"', $kept, 'the file with its link is used');
        $this->assertStringContainsString('Choose a new password</a>', $kept);
    }

    public function testTheBaseThemesLayoutPassesAndRenders(): void
    {
        $file = dirname(__DIR__, 2) . '/content/themes/base/emails/layout.html';
        $html = (string) file_get_contents($file);
        $this->assertSame([], ThemeEmails::problems($html));

        $out = MailLayout::html('Reset', self::message()->blocks(), MailBrand::named('Harbour Books'), new ThemeEmail(null, $file, $html));
        $this->assertMatchesRegularExpression('~>\s*Harbour Books\s*</td>~', $out);
        $this->assertStringContainsString('Choose a new password', $out);
        $this->assertStringNotContainsString('{{', $out);
        $this->assertStringNotContainsString('{!!', $out);
    }

    public function testTheActiveThemeLaysOutEmailsAndAChildThemeOrAnEmailsOwnFileWins(): void
    {
        $this->theme('parent', ['layout' => '<p class="parent">{!! blocks !!}</p>']);
        $this->theme('child', ['auth.password_reset' => '<p class="own">{!! blocks !!}</p>'], parent: 'parent');
        $this->state->save(new PackageState(PackageType::Theme, 'child', true, '1.0.0'));
        $this->boot();

        $this->assertStringStartsWith('<p class="own">', self::message()->render('Ana', 'auth.password_reset'));
        $this->assertStringStartsWith('<p class="parent">', self::message()->render('Ana', 'auth.signup_link'), "another email: the parent's layout");
        $this->assertStringStartsWith('<!DOCTYPE html>', self::message()->render('Ana'), 'without an id, Kestello lays it out');
    }

    public function testActivatingAThemeChecksItsEmailsFirst(): void
    {
        $this->theme('broken', ['layout' => '<script>steal()</script>']);
        $this->theme('fine', ['layout' => self::LAYOUT]);
        $extensions = $this->boot();

        $error = $this->assertThrows(\RuntimeException::class, fn() => $extensions->activateTheme('broken'));
        $this->assertStringContainsString('emails/layout.html: it has scripts (<script>), which emails cannot carry', $error->getMessage());
        $this->assertStringContainsString('emails/layout.html: a layout must place the email with {!! blocks !!}', $error->getMessage());
        $this->assertNull($extensions->activeTheme(), 'nothing changed');

        $this->assertSame('fine', $extensions->activateTheme('fine')->slug());
    }

    public function testPluginCheckReadsAThemesEmails(): void
    {
        $this->theme('checked', ['layout' => '{{ site.name }}', 'shop.order_shipped' => '<form></form>{!! block.anything !!}']);
        $findings = (new PackageChecker())->check("{$this->root}/content/themes/checked", PackageType::Theme);

        $this->assertSame(
            [
                ['level' => 'error', 'file' => 'emails/layout.html', 'line' => 0, 'message' => 'a layout must place the email with {!! blocks !!}'],
                ['level' => 'error', 'file' => 'emails/shop.order_shipped.html', 'line' => 0, 'message' => 'it has forms (<form>), which emails cannot carry'],
            ],
            array_values(array_filter($findings, static fn(array $f): bool => str_starts_with($f['file'], 'emails/'))),
        );
    }
}
