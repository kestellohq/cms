<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlockDefinition;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Blocks\CoreBlocks;
use Kestello\Cms\Content\ContentController;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Blocks: registry, validation on save and render, bindings, visibility, queries. */
final class BlocksTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $this->cms->storage->configure($this->tempDir(), []);
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $this->cms->menus->location('primary', 'Primary');
        ExecutionContext::run('plugin:landing', function (): void {
            $this->cms->content->type('landing')->label('Landing page', 'Landing pages')->path('/go')
                ->fields([Field::blocks('body')->label('Content'), Field::text('secret')->internal(), Field::text('price')]);
        });
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    private function blocks(): BlockRegistry
    {
        return $this->cms->blocks;
    }

    /** @return array<string, mixed> */
    private static function node(string $type, array $props = [], array $children = [], array $extra = []): array
    {
        return ['type' => $type, 'props' => $props, 'children' => $children] + $extra;
    }

    public function testValidTreesAreCleanedAndBadOnesExplained(): void
    {
        [$clean, $errors] = $this->blocks()->validate([
            self::node('core/heading', ['text' => '  Hello  ', 'level' => '2', 'colour' => 'red']),
            self::node('core/text', ['text' => '<p>Hi <script>alert(1)</script><b>there</b></p>']),
            self::node('core/columns', ['layout' => '1-1'], [
                self::node('core/column', [], [self::node('core/button', ['text' => 'Go', 'link' => '/contact'])]),
                self::node('core/column'),
            ]),
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(['text' => 'Hello', 'level' => '2'], (array) $clean[0]['props'], 'trimmed; unknown props are dropped');
        $this->assertSame('<p>Hi <b>there</b></p>', ((array) $clean[1]['props'])['text'], 'rich text is sanitized like entry fields');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $clean[0]['id'], 'nodes get stable ids');
        $this->assertSame('core/button', $clean[2]['children'][0]['children'][0]['type']);

        $messages = $this->blocks()->validate([
            self::node('core/nope'),
            self::node('core/column'),
            self::node('core/columns', [], [self::node('core/heading', ['text' => 'x'])]),
            self::node('core/heading'),
            self::node('core/button', ['text' => 'Bad', 'link' => 'javascript:alert(1)']),
            self::node('core/heading', ['text' => ['bind' => 'entry.nope']]),
            self::node('core/heading', ['text' => 'x', 'level' => ['bind' => 'entry.title']]),
            self::node('core/spacer', [], [self::node('core/spacer')]),
            self::node('core/spacer', [], [], ['visibility' => ['auth' => 'robots']]),
        ])[1];
        $expected = [
            'Block 1: unknown block type "core/nope".',
            'Block 2 (Column): can\'t be placed here.',
            'Block 3 (Columns) › Block 1 (Heading): can\'t be placed inside Columns.',
            'Block 4 (Heading), Text: ',
            'Block 5 (Button), Link: Use a path like /about',
            'Block 6 (Heading), Text: unknown data "entry.nope".',
            'Block 7 (Heading), Level: can\'t be bound to data.',
            'Block 8 (Space): holds no other blocks.',
            'Block 9 (Space): visibility.auth is "guest" or "user".',
        ];
        $this->assertCount(count($expected), $messages, implode("\n", $messages));
        foreach ($expected as $i => $start) {
            $this->assertTrue(str_starts_with($messages[$i], $start), "{$start} … got: {$messages[$i]}");
        }

        $deep = self::node('core/group');
        for ($i = 0; $i < BlockRegistry::MAX_DEPTH + 1; $i++) {
            $deep = self::node('core/group', [], [$deep]);
        }
        $this->assertStringContainsString('at most ' . BlockRegistry::MAX_DEPTH . ' levels', implode(' ', $this->blocks()->validate([$deep])[1]));
        $this->assertSame([], $this->blocks()->validate([self::node('core/heading', ['text' => 'x'])], ['core/text'])[0], 'a field can limit its top-level blocks');
    }

    public function testBoundValuesAreSanitizedLikeTypedOnes(): void
    {
        $entry = $this->json($this->api('POST', '/content/landing/entries', ['title' => 'Hi <img src=x onerror=alert(1)><script>alert(2)</script>', 'status' => 'published', 'data' => ['price' => 'javascript:alert(1)']]))['data'];
        $model = Entry::query()->find($entry['id']);
        $out = $this->blocks()->prepare([
            self::node('core/text', ['text' => ['bind' => 'entry.title']]),
            self::node('core/button', ['text' => 'Go', 'link' => ['bind' => 'entry.fields.price']]),
        ], new BlockContext($model, $this->cms->content->get('landing')));
        $html = (string) ((array) $out[0]['props'])['text'];
        $this->assertFalse(str_contains($html, 'onerror') || str_contains($html, '<script'), 'rich text from a binding is sanitized: ' . $html);
        $this->assertFalse(isset(((array) $out[1]['props'])['link']), 'an unsafe bound link is dropped');
    }

    public function testRenderingDropsTamperingAndResolvesData(): void
    {
        $entry = $this->json($this->api('POST', '/content/landing/entries', ['title' => 'Spring sale', 'status' => 'published', 'data' => ['secret' => 'hunter2', 'price' => '19']]))['data'];
        $model = Entry::query()->find($entry['id']);
        $type = $this->cms->content->get('landing');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $tree = [
            self::node('core/heading', ['text' => ['bind' => 'entry.title']]),
            self::node('core/text', ['text' => '<p>ok</p>', 'onclick' => 'x']),
            ['type' => 'core/text', 'props' => ['text' => '<p>tampered</p><script>x</script>']],   // stored without sanitizing
            self::node('evil/block'),
            self::node('core/heading', ['text' => ['bind' => 'entry.fields.secret']]),
            self::node('core/heading', ['text' => ['bind' => 'entry.fields.price']]),
            self::node('core/heading', ['text' => 'members'], [], ['visibility' => ['auth' => 'user']]),
            self::node('core/heading', ['text' => 'guests'], [], ['visibility' => ['auth' => 'guest']]),
            self::node('core/heading', ['text' => 'editors'], [], ['visibility' => ['roles' => ['editor']]]),
            self::node('core/heading', ['text' => 'later'], [], ['visibility' => ['from' => '2099-01-01T00:00:00Z']]),
            self::node('core/heading', ['text' => 'over'], [], ['visibility' => ['until' => $now]]),
            self::node('core/embed', ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Video']),
        ];
        $guest = $this->blocks()->prepare($tree, new BlockContext($model, $type));
        $texts = array_map(static fn($n) => ((array) $n['props'])['text'] ?? null, $guest);
        $this->assertSame('Spring sale', $texts[0], 'bindings resolve on the server');
        $this->assertSame('<p>ok</p>', $texts[1]);
        $this->assertSame('<p>tampered</p>', $texts[2], 'a row edited in the database is sanitized again on render');
        $this->assertSame(['Spring sale', '<p>ok</p>', '<p>tampered</p>', null, '19', 'guests', null], array_slice($texts, 0, 7), 'unknown blocks go; internal fields are unreachable; guests see guest blocks');
        $this->assertSame('@core/blocks/Heading', $guest[0]['component']);
        $embed = ((array) end($guest)['props'])['embed'];
        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'], $embed, 'privacy-enhanced embed');

        $editor = $this->blocks()->prepare($tree, new BlockContext($model, $type, null, ['id' => 9, 'role' => 'editor']));
        $shown = array_values(array_filter(array_map(static fn($n) => ((array) $n['props'])['text'] ?? null, $editor), static fn($t) => in_array($t, ['members', 'guests', 'editors', 'later', 'over'], true)));
        $this->assertSame(['members', 'editors'], $shown, 'visibility is decided on the server; hidden blocks are not sent');

        $this->assertNull(CoreBlocks::embed('https://evil.test/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(CoreBlocks::embed('javascript:alert(1)//youtube.com'));
        $this->assertSame('https://player.vimeo.com/video/76979871?autoplay=1&dnt=1', CoreBlocks::embed('https://vimeo.com/76979871')['src']);
    }

    public function testQueryLoopsRepeatTheirBlocksPerEntry(): void
    {
        foreach (['First', 'Second', 'Third'] as $i => $title) {
            $this->api('POST', '/content/post/entries', ['title' => $title, 'status' => 'published', 'published_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600 * (3 - $i))]);
        }
        $this->api('POST', '/content/post/entries', ['title' => 'Draft', 'data' => []]);
        $tree = [self::node('core/query', ['type' => 'post', 'limit' => 2], [
            self::node('core/entry-field', ['field' => 'title', 'tag' => 'h3', 'link' => true]),
            self::node('core/heading', ['text' => ['bind' => 'entry.title']]),
        ])];
        [$clean, $errors] = $this->blocks()->validate($tree);
        $this->assertSame([], $errors);
        $out = $this->blocks()->prepare($clean, new BlockContext());
        $query = $out[0];
        $this->assertSame(2, ((array) $query['props'])['count']);
        $this->assertCount(2, $query['children'], 'one item per entry, drafts left out');
        $item = $query['children'][0];
        $this->assertSame([CoreBlocks::QUERY_ITEM, '/blog/third'], [$item['component'], ((array) $item['props'])['url']]);
        $field = (array) $item['children'][0]['props'];
        $this->assertSame(['text', 'Third', '/blog/third'], [$field['kind'], $field['value'], $field['url']], 'newest first, fields of the loop item');
        $this->assertSame('Second', ((array) $query['children'][1]['children'][1]['props'])['text'], 'bindings inside a loop read its item');
        $this->assertSame(['@core/blocks/Query', CoreBlocks::QUERY_ITEM, '@core/blocks/EntryField', '@core/blocks/Heading'], BlockRegistry::components($out));

        $many = array_fill(0, BlockContext::MAX_QUERIES + 2, self::node('core/query', ['type' => 'post', 'limit' => 1], [self::node('core/entry-field', ['field' => 'title'])]));
        $counts = array_map(static fn($n) => ((array) $n['props'])['count'], $this->blocks()->prepare($many, new BlockContext()));
        $this->assertSame(BlockContext::MAX_QUERIES, array_sum($counts), 'a page runs a bounded number of queries');
    }

    public function testBlocksFieldsSaveTrackMediaAndRenderPublicly(): void
    {
        $bad = $this->api('POST', '/content/landing/entries', ['title' => 'x', 'data' => ['body' => [self::node('core/button', ['text' => 'Go'])]]]);
        $this->assertSame(422, $bad->statusCode());
        $this->assertStringContainsString('Block 1 (Button), Link:', json_encode($this->json($bad)['errors']));

        $png = (function (): string {
            ob_start();
            imagepng(imagecreatetruecolor(8, 6));
            return (string) ob_get_clean();
        })();
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'pic.png', 'size' => strlen($png)]))['data'];
        $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $png);
        $media = $this->json($this->api('POST', "/media/uploads/{$up['id']}/complete"))['data'];

        $entry = $this->json($this->api('POST', '/content/landing/entries', ['title' => 'Home', 'status' => 'published', 'data' => ['body' => [
            self::node('core/group', [], [self::node('core/image', ['image' => $media['id'], 'caption' => 'Look'])]),
        ]]]))['data'];
        $this->assertSame([['id' => $entry['id'], 'type' => 'landing', 'title' => 'Home', 'status' => 'published', 'field' => 'body']], $this->json($this->api('GET', "/media/{$media['id']}"))['data']['usage'], 'images inside blocks count as used');

        $public = ContentController::present(Entry::query()->find($entry['id']), $this->cms->content->get('landing'), full: true);
        $this->assertSame(['body'], $public['blocks']);
        $image = (array) $public['data']->body[0]['children'][0]['props'];
        $this->assertSame([$media['id'], 8, 6, 'Look'], [$image['image']['id'], $image['image']['width'], $image['image']['height'], $image['caption']], 'media ids become files for <KImage>');

        $list = $this->json($this->api('GET', '/blocks'))['data'];
        $names = array_column($list['blocks'], 'name');
        foreach (['core/group', 'core/columns', 'core/column', 'core/heading', 'core/text', 'core/image', 'core/button', 'core/list', 'core/quote', 'core/embed', 'core/spacer', 'core/menu', 'core/query', 'core/entry-field'] as $core) {
            $this->assertContains($core, $names);
        }
        $query = $list['blocks'][array_search('core/query', $names, true)];
        $this->assertSame([['value' => 'page', 'label' => 'Pages'], ['value' => 'post', 'label' => 'Posts'], ['value' => 'landing', 'label' => 'Landing pages']], $query['fields'][0]['options']['choices'], 'choices come from the registered types');
        $this->assertContains(['path' => 'entry.title', 'label' => 'Title'], $list['bindings']);
        $button = $list['blocks'][array_search('core/button', $names, true)];
        $link = $button['fields'][array_search('link', array_column($button['fields'], 'name'), true)];
        $this->assertSame('Such as /about or https://example.com.', $link['help'], 'a link field says what it takes, under the box');
        $this->assertArrayNotHasKey('placeholder', (array) $link['options'], 'not as text that vanishes on typing');
    }

    public function testRichTextBodiesBecomeATextBlock(): void
    {
        $this->assertSame('blocks', $this->cms->content->get('page')->getField('body')->type, 'page bodies are block trees');
        [$clean, $errors] = $this->blocks()->validate('<p>Old <script>x</script>page</p>');
        $this->assertSame([], $errors);
        $this->assertSame(['core/text', '<p>Old page</p>'], [$clean[0]['type'], ((array) $clean[0]['props'])['text']], 'HTML saved before the switch is kept as one text block');
        $this->assertSame([], $this->blocks()->validate('')[0]);
        $this->assertSame('<p>Old page</p>', ((array) $this->blocks()->prepare('<p>Old page</p>', new BlockContext())[0]['props'])['text']);
    }

    public function testPluginsNameTheirBlocks(): void
    {
        ExecutionContext::run('plugin:acme', function (): void {
            $def = $this->blocks()->register(BlockDefinition::make('acme/pricing')->fields([Field::text('title')->required()]));
            $this->assertSame('@acme/blocks/Pricing', $def->getComponent());
            $this->assertThrows(\InvalidArgumentException::class, fn() => $this->blocks()->register(BlockDefinition::make('core/heading')));
            $this->assertThrows(\InvalidArgumentException::class, fn() => $this->blocks()->register(BlockDefinition::make('other/thing')));
        });
        $this->assertThrows(\InvalidArgumentException::class, fn() => BlockDefinition::make('NoVendor'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => BlockDefinition::make('acme/x')->category('nope'));
        $this->assertSame([], $this->blocks()->validate([self::node('acme/pricing', ['title' => 'Plans'])])[1]);
    }

    public function testRequiredTextMustHoldText(): void
    {
        foreach (['', '   ', null] as $empty) {
            $errors = $this->blocks()->validate([self::node('core/heading', ['text' => $empty])])[1];
            $this->assertCount(1, $errors, var_export($empty, true) . ': ' . implode(' | ', $errors));
            $this->assertStringStartsWith('Block 1 (Heading), Text: ', $errors[0]);
        }
        $this->assertSame([], $this->blocks()->validate([self::node('core/heading', ['text' => ['bind' => 'entry.title']])])[1], 'data counts as text');
    }

    public function testABrokenBlockIsDroppedNotWhatHoldsIt(): void
    {
        $tree = [self::node('core/group', [], [
            self::node('core/heading', ['text' => 'Kept']),
            ['type' => 'core/heading', 'id' => 'broken1', 'props' => ['text' => ''], 'children' => []],      // stored without validation
        ])];
        $out = $this->blocks()->prepare($tree, new BlockContext());
        $this->assertCount(1, $out, 'the group stays');
        $this->assertSame(['Kept'], array_map(static fn($n) => $n['props']->text, $out[0]['children']), 'only the broken heading goes');
    }

    /** A real image in the library (the chunked upload API), for blocks that pick one. */
    private function uploadImage(): int
    {
        $img = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'bread.png', 'size' => strlen($bytes)]))['data'];
        $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $bytes);
        $r = $this->api('POST', "/media/uploads/{$up['id']}/complete", []);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return (int) $this->json($r)['data']['id'];
    }

    public function testImageVideoAndTextBlockFields(): void
    {
        $id = $this->uploadImage();
        $image = self::node('core/image', ['image' => $id, 'scale' => 45, 'align' => 'wrap-start', 'ratio' => '4/3', 'corners' => 'round']);
        [$clean, $errors] = $this->blocks()->validate([$image]);
        $this->assertSame([], $errors);
        $this->assertSame(['image' => $id, 'scale' => 45, 'align' => 'wrap-start', 'ratio' => '4/3', 'corners' => 'round'], (array) $clean[0]['props']);

        $bad = $this->blocks()->validate([
            self::node('core/image', ['image' => $id, 'scale' => 5]),
            self::node('core/image', ['image' => $id, 'scale' => 101]),
            self::node('core/image', ['image' => $id, 'align' => 'float-left']),
            self::node('core/image', ['image' => $id, 'ratio' => '5/4']),
        ])[1];
        $this->assertCount(4, $bad, implode("\n", $bad));
        foreach (['Size (%)', 'Size (%)', 'Position', 'Shape'] as $i => $label) {
            $this->assertStringStartsWith('Block ' . ($i + 1) . " (Image), {$label}: ", $bad[$i]);
        }

        // a video needs neither a file nor an address to be valid: the editor adds one later
        $this->assertSame([], $this->blocks()->validate([self::node('core/embed', [])])[1]);
        $this->assertSame([], $this->blocks()->validate([self::node('core/embed', ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'start' => 90])])[1]);
        $this->assertNotSame([], $this->blocks()->validate([self::node('core/embed', ['start' => -1])])[1]);

        $yt = CoreBlocks::embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 90);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0&start=90', $yt['src']);
        $this->assertStringEndsWith('&rel=0', CoreBlocks::embed('https://youtu.be/dQw4w9WgXcQ')['src'], 'no start: no parameter');
        $this->assertSame('https://player.vimeo.com/video/76979871?autoplay=1&dnt=1#t=30s', CoreBlocks::embed('https://vimeo.com/76979871', 30)['src']);
        $this->assertStringEndsWith('start=86400', CoreBlocks::embed('https://youtu.be/dQw4w9WgXcQ', 999999)['src'], 'start is capped');
        $this->assertNull(CoreBlocks::embed('javascript:alert(1)//youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(CoreBlocks::embed('https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(CoreBlocks::embed('https://www.youtube.com/watch?v=short'));

        [$clean, $errors] = $this->blocks()->validate([
            self::node('core/separator', ['style' => 'dots']),
            self::node('core/code', ['code' => "<?php echo 1;\n", 'language' => 'PHP']),
            self::node('core/details', ['summary' => 'Opening hours', 'text' => '<p>From 7 <script>x</script></p>', 'open' => true]),
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(['look' => 'dots'], (array) $clean[0]['props'], 'saved as "style" once: renamed, since Vue keeps that name for itself');
        $this->assertStringContainsString('<?php echo 1;', ((array) $clean[1]['props'])['code'], 'code is kept as typed (escaped when shown)');
        $this->assertSame('<p>From 7 </p>', ((array) $clean[2]['props'])['text']);
        $bad = $this->blocks()->validate([self::node('core/separator', ['style' => 'zigzag']), self::node('core/code', []), self::node('core/details', ['text' => '<p>x</p>'])])[1];
        $this->assertCount(3, $bad, implode("\n", $bad));
    }

    public function testFieldsCannotUseNamesVueKeeps(): void
    {
        $def = BlockDefinition::make('acme/box')->fields([Field::text('class')]);
        $this->assertThrows(\InvalidArgumentException::class, static fn() => $def->getFields());
        foreach ($this->blocks()->all() as $block) {
            $block->getFields();      // every core block passes
        }
    }
}
