<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Design\DesignModule;
use Kestello\Cms\Design\ThemeStyles;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Tests\Auth\AuthTestCase;

/** Design tokens, style variations, custom CSS, the stylesheet and revisions. */
final class StylesTest extends AuthTestCase
{
    private const array STYLES = [
        'groups' => ['colors' => 'Colours', 'shape' => 'Shape'],
        'tokens' => [
            'ink' => ['type' => 'color', 'label' => 'Text', 'group' => 'colors', 'value' => '#222222'],
            'paper' => ['type' => 'color', 'label' => 'Page', 'group' => 'colors', 'value' => '#ffffff'],
            'accent' => ['type' => 'color', 'label' => 'Accent', 'group' => 'colors', 'value' => '#1e3a8a'],
            'radius' => ['type' => 'size', 'label' => 'Corners', 'group' => 'shape', 'value' => 8, 'unit' => 'px', 'min' => 0, 'max' => 40],
            'font' => ['type' => 'font', 'label' => 'Text font', 'value' => 'system-sans'],
            'broken' => ['type' => 'color', 'value' => 'red; } body { display: none'],
            'Bad Name' => ['type' => 'color', 'value' => '#000000'],
        ],
        'fonts' => [
            'house' => ['label' => 'House', 'stack' => "'House Sans', sans-serif"],
            'evil' => ['label' => 'Evil', 'stack' => 'x; } body { background: url(//evil.example) '],
        ],
        'contrast' => [['fg' => 'ink', 'bg' => 'paper', 'label' => 'Text'], ['fg' => 'nope', 'bg' => 'paper']],
        'variations' => ['dark' => ['label' => 'Dark', 'values' => ['ink' => '#eeeeee', 'paper' => '#111111', 'unknown' => '#000000']]],
        'email' => [
            'page' => 'paper', 'ink' => 'ink', 'accent' => 'accent', 'accent-ink' => '#fff', 'font' => 'font',
            'nope' => 'ink', 'muted' => 'red; x', 'line' => 'missing',
            'dark' => ['card' => '#101010', 'ink' => '#f0f0f0'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $root = $this->cms->paths->root();
        foreach (['parent' => null, 'kid' => 'parent'] as $slug => $parent) {
            mkdir("{$root}/content/themes/{$slug}", 0777, true);
            file_put_contents("{$root}/content/themes/{$slug}/theme.json", json_encode(array_filter(['slug' => $slug, 'name' => ucfirst($slug), 'version' => '1.0.0', 'requires' => ['core' => '^0.1'], 'parent' => $parent])));
        }
        file_put_contents("{$root}/content/themes/parent/styles.json", json_encode(self::STYLES));
        // the child changes one default and adds a token
        file_put_contents("{$root}/content/themes/kid/styles.json", json_encode(['tokens' => ['accent' => ['type' => 'color', 'label' => 'Accent', 'group' => 'colors', 'value' => '#0f766e'], 'gap' => ['type' => 'size', 'label' => 'Gap', 'value' => 1, 'unit' => 'rem', 'min' => 0, 'max' => 4, 'step' => 0.25]], 'email' => ['card' => 'paper']]));

        $state = new ArrayStateStore();
        $state->save(new \Kestello\Cms\Extension\PackageState(\Kestello\Cms\Extension\PackageType::Theme, 'kid', true, '1.0.0'));
        $extensions = new ExtensionManager($this->cms, $state, new SuspensionList($root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);

        $module = new DesignModule();
        $module->register($this->cms->container);
        $module->boot($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    private function sheet(?string $v = null): Response
    {
        $this->nextRequest();

        return $this->cms->routes->dispatch(new Request('GET', ThemeStyles::ROUTE, ThemeStyles::ROUTE, [], $v === null ? [] : ['v' => $v], [], [], [], ['REMOTE_ADDR' => '203.0.113.9'], ''));
    }

    private function styles(): ThemeStyles
    {
        $s = $this->cms->container->make(ThemeStyles::class);
        $s->forget();

        return $s;
    }

    public function testTokensComeFromTheThemeChainAndAreChecked(): void
    {
        $data = $this->json($this->api('GET', '/design/styles'))['data'];
        $this->assertSame('kid', $data['theme']['slug']);
        $tokens = $data['definition']['tokens'];
        $this->assertSame(['ink', 'paper', 'accent', 'radius', 'font', 'gap'], array_keys($tokens), 'invalid tokens are left out; the child adds one');
        $this->assertSame('#0f766e', $tokens['accent']['value'], 'the child theme\'s default wins');
        $this->assertTrue(isset($data['definition']['fonts']['house']));
        $this->assertFalse(isset($data['definition']['fonts']['evil']), 'a font stack that could escape the declaration is ignored');
        $this->assertTrue(isset($data['definition']['fonts']['system-serif']), 'system stacks are always offered');
        $this->assertCount(1, $data['definition']['contrast'], 'pairs naming unknown tokens are dropped');
        $this->assertSame(['ink' => '#eeeeee', 'paper' => '#111111'], $data['definition']['variations']['dark']['values']);
        $this->assertSame([], $data['revisions']);

        // until something is published, pages only get the block design presets (the theme's tokens are in its own CSS)
        $css = $this->styles()->publishedCss();
        $this->assertStringNotContainsString('--ink:', $css);
        $this->assertStringContainsString('--k-space-md: 1.5rem;', $css);
        $this->assertStringContainsString('--k-color-ink: var(--ink);', $css, 'the colour tokens are the palette');
        $this->assertCount(1, $this->styles()->stylesheetUrls());

        foreach ([
            ['values' => ['ink' => 'red'], 'values.ink'],
            ['values' => ['ink' => '#12345'], 'values.ink'],
            ['values' => ['radius' => 41], 'values.radius'],
            ['values' => ['radius' => '8px; color: red'], 'values.radius'],
            ['values' => ['font' => 'evil'], 'values.font'],
            ['values' => ['broken' => '#000000'], 'values.broken'],
            ['variation' => 'neon', 'values' => [], 'variation'],
        ] as $case) {
            $field = array_pop($case);
            $r = $this->api('PUT', '/design/styles', $case);
            $this->assertSame(422, $r->statusCode(), json_encode($case));
            $this->assertTrue(isset($this->json($r)['errors'][$field]), $field);
        }
    }

    public function testEmailsTakeTheThemesColoursAndTheSitesChoices(): void
    {
        $email = $this->styles()->email();
        $this->assertSame(['page' => '#ffffff', 'ink' => '#222222', 'accent' => '#0f766e', 'accentInk' => '#ffffff', 'card' => '#ffffff'], $email['light'], 'token names resolve; bad roles, refs and missing tokens are left out; the child adds a role');
        $this->assertSame(['card' => '#101010', 'ink' => '#f0f0f0'], $email['dark']);
        $this->assertSame(\Kestello\Cms\Design\ThemeStyles::SYSTEM_FONTS['system-sans']['stack'], $email['font']);

        $this->assertSame(200, $this->api('PUT', '/design/styles', ['values' => ['accent' => '#7C2D12', 'font' => 'house']])->statusCode());
        $email = $this->styles()->email();
        $this->assertSame('#7c2d12', $email['light']['accent'], 'what the site published wins over the theme default');
        $this->assertSame("'House Sans', sans-serif", $email['font']);

        $html = \Kestello\Cms\Notifications\MailMessage::make('Hi')->paragraph('Hello')->button('Go', 'https://a.test')->render(\Kestello\Cms\Mail\MailBrand::site('Shop'));
        $this->assertStringContainsString('background:#7c2d12;color:#ffffff', $html);
        $this->assertStringContainsString('font-family:&#039;House Sans&#039;, sans-serif', $html, 'escaped inside the attribute, which mail apps read back as quotes');
        $this->assertStringContainsString('.k-card{background:#101010!important;color:#f0f0f0!important}', $html, 'the theme\'s dark colours');
        $this->assertStringContainsString('>Shop</td>', $html);
    }

    public function testPublishingCompilesTheStylesheet(): void
    {
        $r = $this->api('PUT', '/design/styles', ['variation' => 'dark', 'values' => ['accent' => '#B42318', 'radius' => 12, 'font' => 'house', 'gap' => '1.5'], 'custom_css' => '.site-brand { letter-spacing: .02em; scroll-behavior: smooth; }']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $data = $this->json($r)['data'];
        $this->assertSame(['accent' => '#b42318', 'font' => 'house', 'gap' => 1.5, 'radius' => 12], $data['published']['values']);
        $this->assertSame('#111111', $data['resolved']['paper'], 'the variation applies under the site\'s changes');
        $this->assertCount(1, $data['revisions']);

        $css = $this->styles()->publishedCss();
        foreach (['--ink: #eeeeee;', '--paper: #111111;', '--accent: #b42318;', '--radius: 12px;', '--gap: 1.5rem;', "--font: 'House Sans', sans-serif;", 'letter-spacing: .02em'] as $line) {
            $this->assertStringContainsString($line, $css);
        }
        [$url] = $this->styles()->stylesheetUrls();
        $this->assertMatchesRegularExpression('#^/system/theme/styles\.css\?v=[0-9a-f]{12}$#', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $res = $this->sheet($q['v']);
        $this->assertSame(200, $res->statusCode());
        $this->assertSame($css, $res->content());
        $this->assertStringContainsString('immutable', $res->headers()['Cache-Control']);
        $this->assertSame('nosniff', $res->headers()['X-Content-Type-Options']);
        $this->assertSame('no-cache', $this->sheet('stale')->headers()['Cache-Control'], 'an old link must ask again');
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'theme.styles.published')->count());

        // a second publish, then restoring the first version
        $this->assertSame(200, $this->api('PUT', '/design/styles', ['variation' => null, 'values' => ['accent' => '#000000']])->statusCode());
        $this->assertStringContainsString('--paper: #ffffff;', $this->styles()->publishedCss());
        $this->assertStringContainsString('letter-spacing', $this->styles()->publishedCss(), 'custom CSS is kept when not sent');
        $revisions = $this->json($this->api('GET', '/design/styles'))['data']['revisions'];
        $this->assertCount(2, $revisions);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $revisions[0]['created_at'], 'UTC, with its zone');
        $this->assertSame(200, $this->api('POST', "/design/styles/revisions/{$revisions[1]['id']}/restore")->statusCode());
        $this->assertStringContainsString('--accent: #b42318;', $this->styles()->publishedCss());

        $this->assertSame(200, $this->api('DELETE', '/design/styles')->statusCode());
        $this->assertStringNotContainsString('--accent:', $this->styles()->publishedCss(), 'reset goes back to the theme\'s files');
        $revisions = $this->json($this->api('GET', '/design/styles'))['data']['revisions'];
        $this->assertTrue($revisions[0]['reset']);
        $this->assertSame(200, $this->api('POST', "/design/styles/revisions/{$revisions[1]['id']}/restore")->statusCode(), 'and a reset can be undone');
        $this->assertStringContainsString('--accent: #b42318;', $this->styles()->publishedCss());
        $this->assertSame(404, $this->api('POST', '/design/styles/revisions/999999/restore')->statusCode());
    }

    public function testCustomCssIsCheckedAndNeedsItsOwnPermission(): void
    {
        foreach ([
            '</style><script>alert(1)</script>' => 'HTML',
            '@import url(/x.css);' => '@import',
            'body { background: url(https://tracker.example/p.gif) }' => 'this site',
            'body { background: url( "//tracker.example/p.gif" ) }' => 'this site',
            'a { width: expression(alert(1)) }' => 'expression',
            'a { behavior: url(/x.htc) }' => 'behavior',
            'a { background: url(javascript:alert(1)) }' => 'javascript',
            'a { content: "\\3c script" }' => 'Escaped',
            '@font-face { font-family: x; src: local(x), url(/f.woff2) format("woff2"), url(https://fonts.example/x.woff2) }' => 'this site',
        ] as $css => $why) {
            $r = $this->api('PUT', '/design/styles', ['values' => [], 'custom_css' => $css]);
            $this->assertSame(422, $r->statusCode(), $css);
            $this->assertStringContainsString($why, $this->json($r)['errors']['custom_css'][0] ?? '', $css);
        }
        $this->assertSame(200, $this->api('PUT', '/design/styles', ['values' => [], 'custom_css' => 'body { background: url(/assets/paper.webp), url("data:image/png;base64,AAAA"); scroll-behavior: smooth }'])->statusCode());

        // an editor allowed to design, but not to write CSS
        foreach (['editor' => 'Editor', 'author' => 'Author'] as $role => $label) {
            if (!$this->cms->auth->roles->exists($role)) {
                $this->cms->auth->roles->role($role, $label, ['admin.access']);
            }
        }
        $this->cms->auth->roles->grant('editor', 'theme.design');
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());
        $data = $this->json($this->api('GET', '/design/styles'))['data'];
        $this->assertFalse($data['can']['custom_css']);
        $this->assertSame(403, $this->api('PUT', '/design/styles', ['values' => [], 'custom_css' => 'body { color: red }'])->statusCode());
        $this->assertSame(200, $this->api('PUT', '/design/styles', ['values' => ['radius' => 4]])->statusCode(), 'tokens without CSS are fine');
        $this->assertStringContainsString('scroll-behavior', $this->styles()->publishedCss(), 'the existing custom CSS stays');

        $this->api('DELETE', '/auth/session');
        $this->createUser(['username' => 'au', 'email' => 'au@example.test', 'role' => 'author']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'au', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/design/styles')->statusCode(), 'designing needs theme.design');
    }

    public function testThemePresetsAreWhatBlocksAreStyledWith(): void
    {
        $root = $this->cms->paths->root();
        file_put_contents("{$root}/content/themes/kid/styles.json", json_encode(['presets' => [
            'color' => ['brand' => ['label' => 'Brand', 'value' => 'var(--accent)'], 'Bad Key' => ['value' => '#fff'], 'evil' => ['value' => 'red; } body { display: none']],
            'space' => ['tight' => ['label' => 'Tight', 'value' => '0.25rem'], 'fluid' => ['value' => 'clamp(1rem, 2vw + 1rem, 3rem)']],
            'shadow' => ['x' => ['value' => '1px']],
        ]]));
        $this->styles()->forget();
        $presets = $this->styles()->presets();
        $this->assertSame(['brand'], array_keys($presets['color']), 'invalid names and values are ignored');
        $this->assertSame(['tight', 'fluid'], array_keys($presets['space']));
        $this->assertArrayHasKey('lg', $presets['font'], 'kinds the theme leaves out get the defaults');
        $this->assertArrayNotHasKey('shadow', $presets);
        $css = $this->styles()->publishedCss();
        $this->assertStringContainsString('--k-color-brand: var(--accent);', $css);
        $this->assertStringContainsString('--k-space-fluid: clamp(1rem, 2vw + 1rem, 3rem);', $css);
        $this->assertStringNotContainsString('display: none', $css);

        // blocks are checked against the theme's presets: saving reports a missing one, rendering drops it
        $this->cms->blocks->register(\Kestello\Cms\Blocks\BlockDefinition::make('acme/box')->supports(['color', 'spacing']));
        $node = ['type' => 'acme/box', 'props' => [], 'children' => [], 'design' => ['base' => ['padding-top' => 'tight', 'background' => 'brand'], 'phone' => ['padding-top' => 'lg']]];
        [, $errors] = $this->cms->blocks->validate([$node]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('design.phone.padding-top: "lg" isn\'t one of the theme\'s spacing sizes (tight, fluid).', $errors[0]);
        unset($node['design']['phone']);
        [$clean, $errors] = $this->cms->blocks->validate([$node]);
        $this->assertSame([], $errors);
        $this->assertSame(['base' => ['padding-top' => 'tight', 'background' => 'brand']], $clean[0]['design']);
    }
}
