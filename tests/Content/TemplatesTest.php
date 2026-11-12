<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Design\DesignModule;
use Kestello\Cms\Design\ThemeTemplates;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Templates, template parts, patterns, drafts and revisions. */
final class TemplatesTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $root = $this->cms->paths->root();
        foreach (['parent' => null, 'kid' => 'parent'] as $slug => $parent) {
            mkdir("{$root}/content/themes/{$slug}", 0777, true);
            file_put_contents("{$root}/content/themes/{$slug}/theme.json", json_encode(array_filter(['slug' => $slug, 'name' => ucfirst($slug), 'version' => '1.0.0', 'requires' => ['core' => '^0.1'], 'parent' => $parent])));
        }
        $this->file('parent', 'parts/header', ['title' => 'Header', 'area' => 'header', 'blocks' => [self::heading('Parent header')]]);
        $this->file('kid', 'parts/header', ['title' => 'Header', 'area' => 'header', 'blocks' => [self::heading('Kid header')]]);
        $this->file('parent', 'templates/entry-post', ['title' => 'Single post', 'blocks' => [
            ['type' => 'core/template-part', 'id' => 'p1', 'props' => ['part' => 'header']],
            ['type' => 'core/entry-field', 'id' => 'f1', 'props' => ['field' => 'title', 'tag' => 'h2']],
            ['type' => 'core/entry-content', 'id' => 'c1', 'props' => []],
            ['type' => 'core/nonsense', 'id' => 'x1', 'props' => []],
        ]]);
        $this->file('parent', 'templates/wide', ['title' => 'Wide', 'for' => ['page', 'nope'], 'layout' => 'bare', 'blocks' => [self::heading('Wide page'), ['type' => 'core/entry-content', 'id' => 'c2', 'props' => []]]]);
        $this->file('parent', 'templates/plain', ['title' => 'Plain', 'for' => ['page'], 'blocks' => [self::heading('Plain top')]]);
        $this->file('parent', 'patterns/hero', ['title' => 'Hero', 'category' => 'Heroes', 'blocks' => [self::heading('Big hello')]]);
        file_put_contents("{$root}/content/themes/parent/templates/broken.json", '{not json');

        $state = new ArrayStateStore();
        $state->save(new \Kestello\Cms\Extension\PackageState(\Kestello\Cms\Extension\PackageType::Theme, 'kid', true, '1.0.0'));
        $extensions = new ExtensionManager($this->cms, $state, new SuspensionList($root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);

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

    private function file(string $theme, string $path, array $data): void
    {
        $file = $this->cms->paths->root() . "/content/themes/{$theme}/{$path}.json";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, json_encode($data));
    }

    private static function heading(string $text): array
    {
        return ['type' => 'core/heading', 'id' => substr(md5($text), 0, 8), 'props' => ['text' => $text, 'level' => '2']];
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->cms->routes->dispatch(new Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), [], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    /** Texts of every heading in a prepared tree. @return list<string> */
    private static function texts(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $props = (array) $n['props'];
            if (isset($props['text'])) {
                $out[] = strip_tags($props['text']);       // a text block's paragraphs read as their words
            }
            if (isset($props['value']) && is_string($props['value'])) {
                $out[] = $props['value'];
            }
            if (isset($props['html'])) {
                $out[] = strip_tags($props['html']);
            }
            $out = [...$out, ...self::texts($n['children'] ?? [])];
        }

        return $out;
    }

    private function post(string $title, array $extra = []): array
    {
        $r = $this->api('POST', '/content/post/entries', ['title' => $title, 'status' => 'published', 'data' => ['body' => '<p>Body of ' . $title . '</p>']] + $extra);
        $this->assertTrue(in_array($r->statusCode(), [200, 201], true), $r->content());

        return $this->json($r)['data'];
    }

    /** The list reads a theme's documents once, however many there are (it ran 168 queries for one list). */
    public function testTheListAsksTheDatabaseTheSameNumberOfTimesForAnyNumberOfDocuments(): void
    {
        $documents = $this->cms->container->make(\Kestello\Cms\Design\ThemeDocuments::class);
        $asked = static function (\Closure $list) use (&$count): int {
            $count = 0;
            $list();

            return $count;
        };
        $count = 0;
        \Kestello\Framework\Database\DatabaseManager::getConnection()->listen(static function (string $sql) use (&$count): void {
            if (str_contains($sql, 'theme_documents')) {
                $count++;
            }
        });
        $list = fn() => $this->assertSame(200, $this->api('GET', '/design/templates')->statusCode());

        $documents->save('kid', 'template', 'site-0', ['title' => 'Site 0', 'blocks' => []], null);
        $few = $asked($list);
        foreach (range(1, 12) as $i) {
            $documents->save('kid', 'template', "site-{$i}", ['title' => "Site {$i}", 'blocks' => []], null);
            $documents->save('kid', 'part', "part-{$i}", ['title' => "Part {$i}", 'area' => 'header', 'blocks' => []], null, 'draft');
        }
        $many = $asked($list);
        $this->assertSame($few, $many, "theme_documents queries for 1 document: {$few}; for 25: {$many}");
        $this->assertTrue($many <= count(\Kestello\Cms\Design\ThemeDocuments::KINDS), "one per kind at most, not {$many}");
    }

    public function testThemeFilesAreListedWithChildOverrides(): void
    {
        $data = $this->json($this->api('GET', '/design/templates'))['data'];
        $this->assertSame(['entry-post', 'plain', 'wide'], array_column($data['template'], 'key'), 'invalid JSON is skipped');
        $this->assertSame(['header'], array_column($data['part'], 'key'));
        $this->assertSame('theme', $data['part'][0]['source']);
        $wide = $data['template'][2];
        $this->assertSame(['page'], $wide['for'], 'unknown content types are dropped');

        $t = $this->cms->container->make(ThemeTemplates::class);
        $this->assertSame('Kid header', ((array) $t->get('part', 'header')['blocks'][0]['props'])['text'], 'the child\'s file wins');
        $this->assertCount(3, $t->get('template', 'entry-post')['blocks'], 'unknown blocks in a theme file are dropped');
        $this->assertSame([['key' => 'plain', 'title' => 'Plain'], ['key' => 'wide', 'title' => 'Wide']], $t->choices('page'));
        $this->assertSame([], $t->choices('post'), 'automatic templates are never offered per entry');

        $types = array_column($this->json($this->api('GET', '/content/types'))['data'], null, 'name');
        $this->assertSame([['key' => 'plain', 'title' => 'Plain'], ['key' => 'wide', 'title' => 'Wide']], $types['page']['templates']);
    }

    public function testEntriesRenderThroughTheirTemplate(): void
    {
        $post = $this->post('Hello');
        $page = $this->visit($post['path'])->payload();
        $this->assertSame('@core/content/Template', $page->component);
        $this->assertSame('entry-post', $page->props['template']['key']);
        $tree = $page->props['template']['blocks'];
        $this->assertSame(['core/template-part', 'core/entry-field', 'core/entry-content'], array_column($tree, 'type'));
        $this->assertSame(['Kid header', 'Hello', 'Body of Hello'], self::texts($tree));
        $this->assertSame('header', ((array) $tree[0]['props'])['area']);
        $this->assertContains('@core/blocks/TemplatePart', $page->preload);

        // a page without a template keeps the theme's own Vue page; choosing "wide" switches it
        $r = $this->api('POST', '/content/page/entries', ['title' => 'About', 'status' => 'published', 'data' => ['body' => [self::heading('About us')]]]);
        $about = $this->json($r)['data'];
        $this->assertSame('@core/content/Entry', $this->visit($about['path'])->payload()->component);
        $this->assertSame(200, $this->api('PATCH', "/content/page/entries/{$about['id']}", ['template' => 'wide'])->statusCode());
        $page = $this->visit($about['path'])->payload();
        $this->assertSame('@core/content/TemplateBare', $page->component);
        $this->assertSame(['Wide page', 'About us'], self::texts($page->props['template']['blocks']));

        // a template that isn't for posts is ignored when a post asks for it
        $this->assertSame(200, $this->api('PATCH', "/content/post/entries/{$post['id']}", ['template' => 'wide'])->statusCode());
        $this->assertSame('entry-post', $this->visit($post['path'])->payload()->props['template']['key']);

        // a template with no Entry content block still shows the page's blocks, after its own
        $this->assertSame(200, $this->api('PATCH', "/content/page/entries/{$about['id']}", ['template' => 'plain'])->statusCode());
        $tree = $this->visit($about['path'])->payload()->props['template']['blocks'];
        $this->assertSame(['Plain top', 'About us'], self::texts($tree));
        $this->assertSame(\Kestello\Cms\Content\ContentController::CONTENT_FALLBACK, $tree[1]['id']);

        // entry content outside a template shows nothing (an entry can't include itself)
        $this->assertSame(200, $this->api('PATCH', "/content/page/entries/{$about['id']}", ['template' => null, 'data' => ['body' => [self::heading('Again'), ['type' => 'core/entry-content', 'id' => 'loop', 'props' => []]]]])->statusCode());
        $entry = $this->visit($about['path'])->payload()->props['entry'];
        $this->assertSame(['core/heading'], array_column($entry['data']->body, 'type'));
    }

    public function testDraftsStayPrivateUntilPublished(): void
    {
        $post = $this->post('Drafty');
        $header = $this->json($this->api('GET', '/design/templates/part/header'))['data'];
        $this->assertSame('theme', $header['source']);
        $this->assertSame([['kind' => 'template', 'key' => 'entry-post', 'title' => 'Single post']], $header['used_by']);

        $r = $this->api('PUT', '/design/templates/part/header', ['title' => 'Header', 'area' => 'header', 'blocks' => [self::heading('Draft header')]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame('Draft header', $this->json($r)['data']['draft']['blocks'][0]['props']['text']);

        $this->assertSame('Kid header', self::texts($this->visit($post['path'])->payload()->props['template']['blocks'])[0], 'visitors see the published version');
        $res = $this->visit($post['path'] . '?design=draft');
        $this->assertSame('Draft header', self::texts($res->payload()->props['template']['blocks'])[0], 'a designer previews the draft');
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame('Kid header', self::texts($this->visit($post['path'] . '?design=draft')->payload()->props['template']['blocks'])[0], 'a guest asking for drafts gets none');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());

        $this->assertSame(200, $this->api('POST', '/design/templates/part/header/publish')->statusCode());
        $this->assertSame('Draft header', self::texts($this->visit($post['path'])->payload()->props['template']['blocks'])[0]);
        $doc = $this->json($this->api('GET', '/design/templates/part/header'))['data'];
        $this->assertSame('customized', $doc['source']);
        $this->assertNull($doc['draft']);
        $this->assertCount(1, $doc['revisions']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'theme.part.published')->count());

        // back to the theme's file, then restore the site's version from the history
        $this->assertSame(200, $this->api('DELETE', '/design/templates/part/header')->statusCode());
        $this->assertSame('Kid header', self::texts($this->visit($post['path'])->payload()->props['template']['blocks'])[0]);
        $revisions = $this->json($this->api('GET', '/design/templates/part/header'))['data']['revisions'];
        $this->assertTrue($revisions[0]['reset']);
        $rr = $this->api('POST', "/design/templates/revisions/{$revisions[1]['id']}/restore");
        $this->assertSame(200, $rr->statusCode(), $rr->content());
        $this->assertSame('Draft header', self::texts($this->visit($post['path'])->payload()->props['template']['blocks'])[0]);

        // a discarded draft leaves the published version alone
        $this->api('PUT', '/design/templates/part/header', ['blocks' => [self::heading('Throwaway')]]);
        $this->assertSame(200, $this->api('DELETE', '/design/templates/part/header/draft')->statusCode());
        $this->assertSame(404, $this->api('DELETE', '/design/templates/part/header/draft')->statusCode());
        $this->assertSame('Draft header', self::texts($this->visit($post['path'] . '?design=draft')->payload()->props['template']['blocks'])[0]);
    }

    public function testSiteTemplatesPartsAndPatterns(): void
    {
        // a template for every entry without one, made on the site
        $r = $this->api('POST', '/design/templates/template', ['key' => 'entry', 'title' => 'Any entry', 'publish' => true, 'blocks' => [
            ['type' => 'core/pattern', 'id' => 'sp', 'props' => ['pattern' => 'promo']],
            ['type' => 'core/entry-content', 'id' => 'c', 'props' => []],
        ]]);
        $this->assertSame(422, $r->statusCode(), 'a synced pattern that does not exist is refused ' . $r->content());

        foreach ([
            [['key' => 'Bad Key', 'blocks' => []], 'key'],
            [['key' => 'header', 'blocks' => []], 'key'],
            [['key' => 'nested', 'blocks' => [['type' => 'core/template-part', 'id' => 'n', 'props' => ['part' => 'header']]]], 'blocks'],
        ] as [$body, $field]) {
            $r = $this->api('POST', '/design/templates/part', $body);
            $this->assertSame(422, $r->statusCode(), json_encode($body));
            $this->assertTrue(isset($this->json($r)['errors'][$field]), $field);
        }
        $this->assertSame(422, $this->api('POST', '/design/templates/template', ['key' => 'entry-recipe', 'blocks' => []])->statusCode(), 'entry-{type} needs a real type');

        $r = $this->api('POST', '/design/templates/pattern', ['key' => 'promo', 'title' => 'Promo', 'synced' => true, 'publish' => true, 'blocks' => [self::heading('Sale today')]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $r = $this->api('POST', '/design/templates/template', ['key' => 'entry', 'title' => 'Any entry', 'publish' => true, 'blocks' => [
            ['type' => 'core/pattern', 'id' => 'sp', 'props' => ['pattern' => 'promo']],
            ['type' => 'core/entry-content', 'id' => 'c', 'props' => []],
        ]]);
        $this->assertSame(200, $r->statusCode(), $r->content());

        $r = $this->api('POST', '/content/page/entries', ['title' => 'Shop', 'status' => 'published', 'data' => ['body' => [self::heading('Our shop')]]]);
        $shop = $this->json($r)['data'];
        $this->assertSame(['Sale today', 'Our shop'], self::texts($this->visit($shop['path'])->payload()->props['template']['blocks']));

        // the synced pattern changes everywhere at once
        $this->assertSame(200, $this->api('PUT', '/design/templates/pattern/promo', ['title' => 'Promo', 'synced' => true, 'publish' => true, 'blocks' => [self::heading('Sale tomorrow')]])->statusCode());
        $this->assertSame(['Sale tomorrow', 'Our shop'], self::texts($this->visit($shop['path'])->payload()->props['template']['blocks']));

        $patterns = array_column(array_filter($this->json($this->api('GET', '/design/patterns'))['data']['patterns'], static fn($p) => $p['source'] !== 'core'), null, 'key');
        $this->assertSame(['hero', 'promo'], array_keys($patterns));
        $this->assertTrue($patterns['promo']['synced']);
        $this->assertSame('Heroes', $patterns['hero']['category']);

        // in use: can't be deleted until the template lets go of it
        $this->assertSame(409, $this->api('DELETE', '/design/templates/pattern/promo')->statusCode());
        $this->assertSame(200, $this->api('DELETE', '/design/templates/template/entry')->statusCode());
        $this->assertSame(200, $this->api('DELETE', '/design/templates/pattern/promo')->statusCode());
        $this->assertSame(404, $this->api('GET', '/design/templates/pattern/promo')->statusCode());
        $this->assertSame('@core/content/Entry', $this->visit($shop['path'])->payload()->component, 'no template: the theme\'s page again');
    }

    public function testTheBaseThemePatternsAreValid(): void
    {
        $dir = dirname(__DIR__, 2) . '/content/themes/base/patterns';
        $t = $this->cms->container->make(ThemeTemplates::class);
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            [, $errors] = $t->normalize('pattern', basename($file, '.json'), json_decode((string) file_get_contents($file), true), strict: true);
            $this->assertSame([], $errors, basename($file));
        }
    }

    public function testScreenSizesAndLocksAreChecked(): void
    {
        $tree = [
            ['type' => 'core/heading', 'id' => 'a1', 'props' => ['text' => 'Wide only', 'level' => '2'], 'visibility' => ['hide' => ['phone']], 'lock' => ['move', 'remove']],
        ];
        [$clean, $errors] = $this->cms->blocks->validate($tree);
        $this->assertSame([], $errors);
        $this->assertSame(['phone'], $clean[0]['visibility']['hide']);
        $this->assertSame(['move', 'remove'], $clean[0]['lock']);
        foreach ([
            ['visibility' => ['hide' => ['watch']]],
            ['visibility' => ['hide' => ['phone', 'tablet', 'desktop']]],
            ['lock' => ['everything']],
        ] as $bad) {
            [, $errors] = $this->cms->blocks->validate([array_merge($tree[0], $bad)]);
            $this->assertCount(1, $errors, json_encode($bad));
        }
        $prepared = $this->cms->blocks->prepare($tree, new \Kestello\Cms\Blocks\BlockContext());
        $this->assertSame(['phone'], $prepared[0]['hide'], 'pages get the screen sizes to hide on');
    }

    public function testTheEntryEditorCanvas(): void
    {
        $node = static fn(string $type, array $props, string $id) => ['type' => $type, 'id' => $id, 'props' => $props, 'children' => []];
        $tree = [
            $node('core/heading', ['text' => 'Typed on the page', 'level' => '2'], 'aaaa1111'),
            $node('core/text', ['text' => ''], 'bbbb2222'),
            $node('core/text', ['text' => '<p>Hi<script>alert(1)</script></p>'], 'cccc3333'),
            $node('core/entry-content', [], 'dddd4444'),
        ];
        $r = $this->api('POST', '/content/page/preview-blocks', ['blocks' => $tree, 'field' => 'body']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $out = $this->json($r)['data']['blocks'];
        $this->assertSame(['aaaa1111', 'bbbb2222', 'cccc3333'], array_column($out, 'id'), 'a block still being written stays, so it can be typed into; template-only blocks stay out');
        $this->assertStringNotContainsString('<script', (string) json_encode($out), 'rich text is cleaned for the canvas too');
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Content\PreviewEntryBlocks::class, $r);
        $this->assertSame(422, $this->api('POST', '/content/post/preview-blocks', ['blocks' => [], 'field' => 'image'])->statusCode(), 'only a blocks field');

        // the canvas: the entry laid out by the site (here through the "wide" template), for whoever may edit it
        $r = $this->api('POST', '/content/page/entries', ['title' => 'Launch', 'template' => 'wide', 'data' => ['body' => [self::heading('Saved heading')]]]);
        $this->assertTrue(in_array($r->statusCode(), [200, 201], true), $r->content());
        $id = $this->json($r)['data']['id'];
        $res = $this->visit("/system/design/canvas?kind=entry&type=page&entry={$id}&field=body");
        $this->assertSame(200, $res->statusCode());
        $page = $res->payload();
        $this->assertSame(['canvas' => true, 'kind' => 'entry', 'field' => 'body', 'entry' => $id], $page->props['design']);
        $this->assertContains('Saved heading', self::texts($page->props['template']['blocks']));
        $body = array_values(array_filter($page->props['template']['blocks'], static fn($n) => $n['type'] === 'core/entry-content'))[0];
        $this->assertSame('body', ((array) $body['props'])['field'], 'the canvas knows where the body goes');
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);

        // the template chosen in the editor, not saved yet: the canvas follows it, and nothing is stored
        // (a page without a template is drawn by the theme's own page: no template blocks at all)
        $texts = fn(string $q) => self::texts($this->visit("/system/design/canvas?kind=entry&type=page&entry={$id}&field=body{$q}")->payload()->props['template']['blocks'] ?? []);
        $this->assertContains('Plain top', $texts('&template=plain'), 'another template');
        $this->assertNotContains('Wide page', $texts('&template=plain'));
        $this->assertNotContains('Wide page', $texts('&template='), 'no template: the saved one no longer shows');
        $this->assertContains('Wide page', $texts(''), 'without the parameter: the saved one');
        $this->assertSame([], $texts('&template=../wide'), 'an unsafe key loads nothing: the type\'s default applies (here none)');
        $this->assertSame('wide', \Kestello\Cms\Content\Entry::query()->find($id)->template, 'previews never save');
        $this->assertSame(200, $this->visit('/system/design/canvas?kind=entry&type=page&field=body&template=plain')->statusCode(), 'for a page not saved yet too');
        $this->assertSame(200, $this->visit('/system/design/canvas?kind=entry&type=page&field=body')->statusCode(), 'a page not saved yet has a canvas too');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=entry&type=post&field=image')->statusCode(), 'not a blocks field');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=entry&type=nope&field=body')->statusCode());

        $this->createUser(['username' => 'sub', 'email' => 'sub@example.test', 'role' => 'subscriber']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'sub', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(404, $this->visit("/system/design/canvas?kind=entry&type=page&entry={$id}&field=body")->statusCode(), 'someone who can\'t edit it sees nothing');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=entry&type=page&field=body')->statusCode());
        $this->assertTrue($this->api('POST', '/content/page/preview-blocks', ['blocks' => [], 'field' => 'body', 'entry' => $id])->statusCode() >= 403);
    }

    public function testTheBuilderCanvasAndPreview(): void
    {
        $post = $this->post('Canvas post');
        $this->api('PUT', '/design/templates/part/header', ['title' => 'Header', 'area' => 'header', 'blocks' => [self::heading('Draft header')]]);
        $header = array_column($this->json($this->api('GET', '/design/templates'))['data']['part'], null, 'key')['header'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $header['info']['updated_at'], 'UTC, with its zone');

        $res = $this->visit('/system/design/canvas?kind=template&key=entry-post&entry=' . $post['id']);
        $this->assertSame(200, $res->statusCode());
        $page = $res->payload();
        $this->assertSame(['canvas' => true, 'kind' => 'template', 'key' => 'entry-post', 'entry' => $post['id']], $page->props['design']);
        $this->assertSame(['Draft header', 'Canvas post', 'Body of Canvas post'], self::texts($page->props['template']['blocks']), 'drafts and the chosen entry');
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $this->assertSame('@core/content/TemplateBare', $this->visit('/system/design/canvas?kind=part&key=header')->payload()->component, 'parts are shown on their own');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=part&key=nope')->statusCode());
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=evil&key=header')->statusCode());

        // an unsaved tree, prepared as the site would show it
        $r = $this->api('POST', '/design/preview', ['entry' => $post['id'], 'blocks' => [
            self::heading('Unsaved'),
            ['type' => 'core/entry-field', 'id' => 'f9', 'props' => ['field' => 'title', 'tag' => 'h2']],
            ['type' => 'core/nonsense', 'id' => 'x9', 'props' => []],
        ]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $data = $this->json($r)['data'];
        $this->assertSame(['Unsaved', 'Canvas post'], self::texts($data['blocks']), 'invalid blocks are left out');
        $this->assertContains('@core/blocks/Heading', $data['components']);

        $entries = $this->json($this->api('GET', '/design/templates/template/entry-post/preview-entries'))['data']['entries'];
        $this->assertSame([$post['id']], array_column($entries, 'id'));
        $this->assertSame([], $this->json($this->api('GET', '/design/templates/template/wide/preview-entries'))['data']['entries'], 'wide is for pages only');

        $this->api('DELETE', '/auth/session');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=part&key=header')->statusCode(), 'only people who design see the canvas');
    }

    public function testCorePatternsComeWithEverySiteAndThemesCanReplaceThem(): void
    {
        $this->file('parent', 'patterns/faq', ['title' => 'Our questions', 'category' => 'Questions', 'blocks' => [self::heading('Ask us')]]);
        $this->cms->container->make(\Kestello\Cms\Design\ThemeTemplates::class)->forget();
        $patterns = array_column($this->json($this->api('GET', '/design/patterns'))['data']['patterns'], null, 'key');
        $core = array_filter($patterns, static fn($p) => $p['source'] === 'core');
        $this->assertGreaterThanOrEqual(30, count($core), 'a library of patterns ships with core');
        $this->assertSame(['theme', 'Our questions'], [$patterns['faq']['source'], $patterns['faq']['title']], 'the theme\'s file replaces core\'s with the same key');
        $this->assertSame(['page-about', 'page-contact', 'page-home', 'page-pricing', 'page-services'], array_keys(array_filter($patterns, static fn($p) => $p['layout'])));
        $this->assertSame('core/cover', $patterns['hero-centred']['blocks'][0]['type']);

        $list = array_column($this->json($this->api('GET', '/design/templates'))['data']['pattern'], 'source', 'key');
        $this->assertSame(['core', 'theme'], [$list['steps'], $list['faq']], 'the admin list says where each comes from');
    }

    public function testEveryCorePatternSavesAsItIs(): void
    {
        $categories = ['Headers', 'Features', 'Text', 'Calls to action', 'Testimonials', 'Pricing', 'Questions', 'About', 'Contact', 'Lists and tables', 'Pages'];
        $files = glob(\Kestello\Cms\Design\ThemeTemplates::CORE_PATTERNS . '/*.json') ?: [];
        $this->assertGreaterThanOrEqual(30, count($files));
        foreach ($files as $file) {
            $key = basename($file, '.json');
            $doc = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            [, $errors] = $this->cms->blocks->validate($doc['blocks']);
            $this->assertSame([], $errors, "{$key}: " . implode(' | ', $errors));
            $this->assertContains($doc['category'], $categories, "{$key}: a known category");
            $this->assertNotSame('', trim($doc['description'] ?? ''), "{$key}: says what it is");
            $this->assertSame($doc['category'] === 'Pages', !empty($doc['layout']), "{$key}: whole pages, and only they, are layouts");
        }
    }

    public function testPatternsFitTheSiteTheyArePlacedOn(): void
    {
        $design = $this->cms->blocks->design;
        $design->usePresets(static fn(): array => ['space' => ['tight' => ['label' => 'Tight', 'value' => '.25rem']]]);
        $tree = [['type' => 'core/group', 'id' => 'g1', 'props' => [], 'design' => ['base' => ['padding-top' => 'lg', 'padding-left' => 'tight', 'background' => 'ink']], 'children' => [
            ['type' => 'core/heading', 'id' => 'h1', 'props' => ['text' => '']],
            ['type' => 'acme/gone', 'id' => 'x1', 'props' => []],
        ]]];
        $fitted = $this->cms->blocks->fit($tree);
        $this->assertSame(['base' => ['padding-left' => 'tight', 'background' => 'ink']], $fitted[0]['design'], 'a size this theme doesn\'t have is left out; its own stay (colours fall back to the defaults)');
        $this->assertSame(['core/heading'], array_column($fitted[0]['children'], 'type'), 'unknown blocks go; an empty heading stays for the editor to fill');
        $left = $this->cms->blocks->validate($fitted)[1];
        $this->assertCount(1, $left, implode(' | ', $left));
        $this->assertStringStartsWith('Block 1 (Group) › Block 1 (Heading), Text:', $left[0], 'nothing but the empty heading to fill in');
        $design->forget();
    }

    public function testPatternPreviewsAreForPeopleWhoEditContent(): void
    {
        $res = $this->visit('/system/design/canvas?kind=pattern&key=steps&preview=1');
        $this->assertSame(200, $res->statusCode());
        $page = $res->payload();
        $this->assertSame('@core/content/TemplateBare', $page->component);
        $this->assertSame(['preview' => true], $page->props['design'], 'shown, not edited');
        $this->assertSame('core/heading', $page->props['template']['blocks'][0]['type']);
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=pattern&key=nope&preview=1')->statusCode());

        $r = $this->api('POST', '/design/templates/pattern', ['key' => 'secret', 'title' => 'Secret', 'blocks' => [self::heading('Not yet')]]);
        $this->assertContains($r->statusCode(), [200, 201], 'a draft: ' . $r->content());
        if (!$this->cms->auth->roles->exists('author')) {
            $this->cms->auth->roles->role('author', 'Author', ['admin.access', 'content.access']);
        }
        $this->createUser(['username' => 'au', 'email' => 'au@example.test', 'role' => 'author']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'au', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(200, $this->visit('/system/design/canvas?kind=pattern&key=steps&preview=1')->statusCode(), 'authors browse patterns too');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=pattern&key=secret&preview=1')->statusCode(), 'but not drafts');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=pattern&key=steps')->statusCode(), 'nor the designer\'s canvas');
        $this->api('DELETE', '/auth/session');
        $this->assertSame(404, $this->visit('/system/design/canvas?kind=pattern&key=steps&preview=1')->statusCode(), 'visitors see nothing');
    }

    public function testDesignersPreviewWithDraftsOnlyTheyMayEdit(): void
    {
        $public = $this->post('Out already');
        $r = $this->api('POST', '/content/post/entries', ['title' => 'Unannounced merger', 'status' => 'draft']);
        $secret = $this->json($r)['data'];
        $this->cms->auth->roles->role('designer', 'Designer', ['admin.access', 'content.access', 'theme.design']);
        $this->createUser(['username' => 'des', 'email' => 'des@example.test', 'role' => 'designer']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'des', 'password' => self::PASSWORD])->statusCode());

        $ids = array_column($this->json($this->api('GET', '/design/templates/template/entry-post/preview-entries'))['data']['entries'], 'id');
        $this->assertSame([$public['id']], $ids, 'published entries, and no drafts of a type they cannot edit');

        $field = ['type' => 'core/entry-field', 'id' => 'f1', 'props' => ['field' => 'title', 'tag' => 'h2']];
        $r = $this->api('POST', '/design/preview', ['entry' => $secret['id'], 'blocks' => [$field]]);
        $this->assertStringNotContainsString('Unannounced', $r->content(), 'nor by asking for one by id');
        $this->assertStringNotContainsString('Unannounced', (string) $this->visit('/system/design/canvas?kind=template&key=entry-post&entry=' . $secret['id'])->content());
        $r = $this->api('POST', '/design/preview', ['entry' => $public['id'], 'blocks' => [$field]]);
        $this->assertSame(['Out already'], self::texts($this->json($r)['data']['blocks']));
    }

    public function testDesigningNeedsThePermission(): void
    {
        if (!$this->cms->auth->roles->exists('author')) {
            $this->cms->auth->roles->role('author', 'Author', ['admin.access', 'content.access']);
        }
        $this->createUser(['username' => 'au', 'email' => 'au@example.test', 'role' => 'author']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'au', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/design/templates')->statusCode());
        $this->assertSame(403, $this->api('PUT', '/design/templates/part/header', ['blocks' => []])->statusCode());
        $this->assertSame(200, $this->api('GET', '/design/patterns')->statusCode(), 'people who edit content may place patterns');
    }
}
