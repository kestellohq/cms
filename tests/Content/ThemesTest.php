<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Design\DesignModule;
use Kestello\Cms\Design\ThemeFonts;
use Kestello\Cms\Design\ThemePackages;
use Kestello\Cms\Design\ThemeStyles;
use Kestello\Cms\Design\ThemeTemplates;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageState;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Support\Zip\Zip;
use Kestello\Tests\Auth\AuthTestCase;

/** Themes made, exported and imported in the admin, theme updates, hosted fonts. */
final class ThemesTest extends AuthTestCase
{
    private string $root;
    private ArrayStateStore $state;

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->root = $this->cms->paths->root();
        mkdir("{$this->root}/content/themes/parent/parts", 0777, true);
        file_put_contents("{$this->root}/content/themes/parent/theme.json", json_encode(['slug' => 'parent', 'name' => 'Parent', 'version' => '1.0.0', 'requires' => ['core' => '^0.1']]));
        file_put_contents("{$this->root}/content/themes/parent/styles.json", json_encode([
            'tokens' => [
                'accent' => ['type' => 'color', 'label' => 'Accent', 'value' => '#112233'],
                'font-body' => ['type' => 'font', 'label' => 'Body', 'value' => 'system-sans'],
            ],
            'variations' => ['dusk' => ['label' => 'Dusk', 'values' => ['accent' => '#445566']]],
        ]));
        $this->part('Theme header');

        $this->state = new ArrayStateStore();
        $this->state->save(new PackageState(PackageType::Theme, 'parent', true, '1.0.0'));
        $this->reboot();
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule(), new DesignModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    /** @return array<string, mixed> the "data" of a response */
    protected function json(Response $response): array
    {
        $json = parent::json($response);

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    /** What the next request sees after a theme switch. */
    private function reboot(): void
    {
        $extensions = new ExtensionManager($this->cms, $this->state, new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);
        if ($this->cms->container->has(ThemeStyles::class)) {
            $this->cms->container->make(ThemeStyles::class)->forget();
            $this->cms->container->make(ThemeTemplates::class)->forget();
        }
    }

    private function part(string $text): void
    {
        file_put_contents("{$this->root}/content/themes/parent/parts/header.json", json_encode(['title' => 'Header', 'area' => 'header', 'blocks' => [
            ['type' => 'core/heading', 'id' => 'h1', 'props' => ['text' => $text, 'level' => '2']],
        ]]));
        $this->cms->container->make(ThemeTemplates::class)->forget();
    }

    private function upload(string $zip, bool $replace = false): Response
    {
        $this->nextRequest();
        $request = new Request(
            'POST',
            '/api/v1/themes/import',
            '/api/v1/themes/import',
            [],
            [],
            $replace ? ['replace' => '1'] : [],
            [],
            ['file' => ['name' => 'theme.zip', 'type' => 'application/zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'size' => filesize($zip)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        );

        return $this->cms->routes->dispatch($request);
    }

    private function zip(array $files): string
    {
        $path = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = Zip::create($path);
        foreach ($files as $name => $contents) {
            $zip->addString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function testEverythingThatCanSwitchTheLiveThemeAsksForARecentSignIn(): void
    {
        foreach ([\Kestello\Cms\Api\Endpoints\Themes\ActivateTheme::class, \Kestello\Cms\Api\Endpoints\Themes\CreateTheme::class] as $class) {
            $this->assertTrue((new $class())->requiresRecentAuth(), "{$class}: creating with activate switches the live theme too");
        }
    }

    public function testTheWizardMakesADataOnlyChildThemeFromTheSite(): void
    {
        // the site changes the accent and the header
        $res = $this->api('PUT', '/design/styles', ['variation' => null, 'values' => ['accent' => '#aa0000'], 'custom_css' => '.x { color: red; }']);
        $this->assertSame('#aa0000', $this->json($res)['resolved']['accent'] ?? null, $res->content());
        $this->assertSame(200, $this->api('PUT', '/design/templates/part/header', ['title' => 'Header', 'area' => 'header', 'publish' => true, 'blocks' => [
            ['type' => 'core/heading', 'id' => 'h1', 'props' => ['text' => 'Site header', 'level' => '2']],
        ]])->statusCode());

        $res = $this->api('POST', '/themes', ['name' => 'Bakery Look', 'parent' => 'parent', 'from_site' => true, 'activate' => true]);
        $this->assertSame(200, $res->statusCode(), $res->content());
        $body = $this->json($res);
        $this->assertSame('bakery-look', $body['created']);
        $this->assertSame('bakery-look', $body['active']);
        $dir = "{$this->root}/content/themes/bakery-look";
        $this->assertTrue(ThemePackages::dataOnly($dir));
        $manifest = json_decode((string) file_get_contents("{$dir}/theme.json"), true);
        $this->assertSame('parent', $manifest['parent']);
        $styles = json_decode((string) file_get_contents("{$dir}/styles.json"), true);
        $this->assertSame(['accent' => '#aa0000'], $styles['values']);
        $this->assertStringContainsString('color: red', $styles['custom_css']);
        $this->assertFileExists("{$dir}/parts/header.json");

        // the new theme shows the site's look as its own defaults
        $this->reboot();
        $css = $this->cms->container->make(ThemeStyles::class);
        $css->forget();
        $this->assertSame('#aa0000', $css->definition()['tokens']['accent']['value']);
        $this->assertStringContainsString('--accent: #aa0000', $css->publishedCss());
        $this->assertStringContainsString('color: red', $css->publishedCss());
        $t = $this->cms->container->make(ThemeTemplates::class);
        $t->forget();
        $this->assertSame('Site header', ((array) $t->get('part', 'header')['blocks'][0]['props'])['text']);
        $this->assertContains('theme.created', $this->auditEvents());

        // names are checked
        $this->assertSame(422, $this->api('POST', '/themes', ['name' => 'Bakery Look', 'parent' => 'parent'])->statusCode());
        $this->assertSame(422, $this->api('POST', '/themes', ['name' => 'X', 'parent' => 'nope'])->statusCode());
        // a variation becomes the defaults
        $this->assertSame(200, $this->api('POST', '/themes', ['name' => 'Dusk', 'parent' => 'bakery-look', 'variation' => 'dusk'])->statusCode());
        $this->assertSame('#445566', json_decode((string) file_get_contents("{$this->root}/content/themes/dusk/styles.json"), true)['values']['accent']);
    }

    public function testExportAndImportRoundTrip(): void
    {
        $this->assertSame(200, $this->api('POST', '/themes', ['name' => 'Shop', 'parent' => 'parent', 'activate' => true])->statusCode());
        $this->reboot();
        $this->assertSame(200, $this->api('POST', '/design/templates/pattern', ['key' => 'promo', 'title' => 'Promo', 'category' => 'Calls', 'publish' => true, 'blocks' => [
            ['type' => 'core/heading', 'id' => 'p1', 'props' => ['text' => 'Sale', 'level' => '2']],
        ]])->statusCode());

        $res = $this->api('GET', '/themes/shop/export');
        $this->assertSame(200, $res->statusCode(), $res->content());
        $this->assertSame('application/zip', ($res->headers()['Content-Type'] ?? null));
        $zip = $this->root . '/exported.zip';
        file_put_contents($zip, $res->content());
        $names = array_map(static fn($e) => $e->name, Zip::open($zip)->entries());
        $this->assertContains('shop/theme.json', $names);
        $this->assertContains('shop/patterns/promo.json', $names);
        // themes with code can't be exported here
        $this->assertSame(409, $this->api('GET', '/themes/parent/export')->statusCode());

        // install it on "another site": delete it here, then import
        $this->assertSame(200, $this->api('POST', '/themes/parent/activate')->statusCode());
        $this->reboot();
        $this->assertSame(200, $this->api('DELETE', '/themes/shop')->statusCode());
        $this->assertFileDoesNotExist("{$this->root}/content/themes/shop/theme.json");
        $res = $this->upload($zip);
        $this->assertSame(200, $res->statusCode(), $res->content());
        $this->assertSame('shop', $this->json($res)['imported']);
        $this->assertFileExists("{$this->root}/content/themes/shop/patterns/promo.json");
        // a second import needs "replace"
        $this->assertSame(422, $this->upload($zip)->statusCode());
        $this->assertSame(200, $this->upload($zip, replace: true)->statusCode());
        $this->assertContains('theme.updated', $this->auditEvents());
        // the running theme can't be deleted, nor a theme with code
        $this->assertSame(409, $this->api('DELETE', '/themes/parent')->statusCode());
    }

    public function testImportsAreDataOnly(): void
    {
        $manifest = json_encode(['slug' => 'evil', 'name' => 'Evil', 'version' => '1.0.0', 'requires' => ['core' => '^0.1'], 'parent' => 'parent']);
        $cases = [
            'php file' => ['evil/theme.json' => $manifest, 'evil/src/Theme.php' => '<?php echo 1;'],
            'vue page' => ['evil/theme.json' => $manifest, 'evil/resources/js/pages/Home.vue' => '<template/>'],
            'stray file' => ['evil/theme.json' => $manifest, 'evil/notes/x.txt' => 'hi'],
            'provider' => ['evil/theme.json' => json_encode(['slug' => 'evil', 'name' => 'Evil', 'version' => '1.0.0', 'requires' => ['core' => '^0.1'], 'parent' => 'parent', 'namespace' => 'Evil\\', 'provider' => 'Evil\\Theme'])],
            'no parent here' => ['evil/theme.json' => json_encode(['slug' => 'evil', 'name' => 'Evil', 'version' => '1.0.0', 'requires' => ['core' => '^0.1'], 'parent' => 'missing'])],
            'bad css' => ['evil/theme.json' => $manifest, 'evil/styles.json' => json_encode(['custom_css' => '@import url(https://evil.test/x.css);'])],
            'no manifest' => ['evil/styles.json' => '{}'],
        ];
        foreach ($cases as $label => $files) {
            $res = $this->upload($this->zip($files));
            $this->assertSame(422, $res->statusCode(), $label . ': ' . $res->content());
        }
        $this->assertFileDoesNotExist("{$this->root}/content/themes/evil/theme.json");
        // a valid one with files at the root of the zip works
        $res = $this->upload($this->zip(['theme.json' => $manifest, 'parts/footer.json' => json_encode(['title' => 'Footer', 'area' => 'footer', 'blocks' => []])]));
        $this->assertSame(200, $res->statusCode(), $res->content());
        $this->assertFileExists("{$this->root}/content/themes/evil/parts/footer.json");
    }

    public function testThemeUpdatesToChangedDocumentsCanBeResolved(): void
    {
        $t = $this->cms->container->make(ThemeTemplates::class);
        $this->assertSame(200, $this->api('PUT', '/design/templates/part/header', ['title' => 'Header', 'area' => 'header', 'publish' => true, 'blocks' => [
            ['type' => 'core/heading', 'id' => 'h1', 'props' => ['text' => 'Mine', 'level' => '2']],
        ]])->statusCode());
        $this->assertFalse($this->json($this->api('GET', '/design/templates/part/header'))['theme_update']);

        // the theme ships a new header
        $this->part('Theme header v2');
        $doc = $this->json($this->api('GET', '/design/templates/part/header'));
        $this->assertTrue($doc['theme_update']);
        $this->assertSame('Theme header v2', $doc['theme_version']['blocks'][0]['props']['text']);
        $this->assertSame('Mine', $doc['published']['blocks'][0]['props']['text']);

        // keep mine: the flag goes away, mine stays
        $doc = $this->json($this->api('POST', '/design/templates/part/header/theme-update', ['action' => 'keep']));
        $this->assertFalse($doc['theme_update']);
        $this->assertSame('Mine', $doc['published']['blocks'][0]['props']['text']);

        // open theirs as a draft to merge by hand
        $this->part('Theme header v3');
        $doc = $this->json($this->api('POST', '/design/templates/part/header/theme-update', ['action' => 'draft']));
        $this->assertSame('Mine', $doc['published']['blocks'][0]['props']['text']);
        $this->assertSame('Theme header v3', $doc['draft']['blocks'][0]['props']['text']);

        // take theirs
        $this->part('Theme header v4');
        $doc = $this->json($this->api('POST', '/design/templates/part/header/theme-update', ['action' => 'theirs']));
        $this->assertSame('Theme header v4', $doc['published']['blocks'][0]['props']['text']);
        $this->assertSame('theme', $doc['source']);
        $this->assertSame(409, $this->api('POST', '/design/templates/part/header/theme-update', ['action' => 'keep'])->statusCode());
        $this->assertFalse($t->hasThemeUpdate('part', 'header'));
    }

    public function testGoogleFontsAreParsedAndHostedHere(): void
    {
        $css = "/* cyrillic */\n@font-face {\n  font-family: 'Inter';\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n  src: url(https://fonts.gstatic.com/s/inter/v18/cyr.woff2) format('woff2');\n  unicode-range: U+0301, U+0400-045F;\n}\n"
            . "/* latin-ext */\n@font-face {\n  font-family: 'Inter';\n  font-style: normal;\n  font-weight: 400;\n  src: url(https://fonts.gstatic.com/s/inter/v18/ext.woff2) format('woff2');\n  unicode-range: U+0100-02BA;\n}\n"
            . "/* latin */\n@font-face {\n  font-family: 'Inter';\n  font-style: italic;\n  font-weight: 700;\n  src: url(https://fonts.gstatic.com/s/inter/v18/lat.woff2) format('woff2');\n  unicode-range: U+0000-00FF, U+2122;\n}\n"
            . "/* latin */\n@font-face {\n  font-style: normal;\n  font-weight: 400;\n  src: url(https://evil.test/x.woff2) format('woff2');\n}\n";
        $faces = ThemeFonts::parse($css);
        $this->assertCount(2, $faces);
        $this->assertSame(['latin-ext', 'latin'], array_column($faces, 'subset'));
        $this->assertSame('italic', $faces[1]['style']);
        $this->assertSame(700, $faces[1]['weight']);

        // a theme package brings its font; the stylesheet serves it from this site
        $manifest = json_encode(['slug' => 'typed', 'name' => 'Typed', 'version' => '1.0.0', 'requires' => ['core' => '^0.1'], 'parent' => 'parent']);
        $zip = $this->zip([
            'typed/theme.json' => $manifest,
            'typed/styles.json' => json_encode(['values' => ['font-body' => 'gf-inter']]),
            'typed/fonts/fonts.json' => json_encode(['gf-inter' => ['family' => 'Inter', 'category' => 'sans-serif', 'files' => [['weight' => 400, 'style' => 'normal', 'subset' => 'latin', 'range' => 'U+0000-00FF', 'file' => '400-normal-latin-0.woff2']]]]),
            'typed/fonts/gf-inter/400-normal-latin-0.woff2' => 'wOF2' . str_repeat("\0", 60),
        ]);
        $res = $this->upload($zip);
        $this->assertSame(200, $res->statusCode(), $res->content());
        $this->assertSame(['gf-inter'], array_column($this->json($res)['fonts'], 'slug'));
        $this->assertSame(200, $this->api('POST', '/themes/typed/activate')->statusCode());
        $this->reboot();
        $styles = $this->cms->container->make(ThemeStyles::class);
        $styles->forget();
        $out = $styles->publishedCss();
        $this->assertStringContainsString("font-family: 'Inter'", $out);
        $this->assertStringContainsString('url(/system/fonts/gf-inter/400-normal-latin-0.woff2)', $out);
        $this->assertStringContainsString("--font-body: 'Inter', sans-serif", $out);
        $this->assertStringNotContainsString('googleapis', $out);
        // its regular file is preloaded with the page
        $this->assertSame(['/system/fonts/gf-inter/400-normal-latin-0.woff2'], $styles->preloadUrls());

        $file = $this->cms->routes->dispatch(new Request('GET', '/system/fonts/gf-inter/400-normal-latin-0.woff2', '/system/fonts/gf-inter/400-normal-latin-0.woff2', [], [], [], [], [], []));
        $this->assertSame(200, $file->statusCode());
        $this->assertSame('font/woff2', ($file->headers()['Content-Type'] ?? null));
        $this->assertSame(404, $this->cms->routes->dispatch(new Request('GET', '/system/fonts/gf-inter/..%2Fx.woff2', '/system/fonts/gf-inter/..%2Fx.woff2', [], [], [], [], [], []))->statusCode());

        // removing it drops the face; the token falls back
        $this->assertSame(200, $this->api('DELETE', '/themes/fonts/gf-inter')->statusCode());
        $styles->forget();
        $this->assertStringNotContainsString('@font-face', $styles->publishedCss());
        // bad names never reach Google
        $this->assertSame(422, $this->api('POST', '/themes/fonts', ['family' => 'Inter; @import', 'weights' => [400]])->statusCode());
    }

    public function testManagingThemesNeedsThePermission(): void
    {
        $this->api('DELETE', '/auth/session');
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/themes')->statusCode());
        $this->assertSame(403, $this->api('POST', '/themes', ['name' => 'X', 'parent' => 'parent'])->statusCode());
        $this->assertSame(403, $this->upload($this->zip(['theme.json' => '{}']))->statusCode());
    }
}
