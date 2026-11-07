<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlockDefinition;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Blocks\CoreListBlocks;
use Kestello\Cms\Blocks\Icons;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\Fields\TableField;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** The section and list blocks: cover, media and text, tabs, buttons, gallery, table, icon list, social links. */
final class SectionBlocksTest extends AuthTestCase
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
        ContentModule::finalize($this->cms);
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
        return ['type' => $type, 'id' => 'b' . substr(md5(serialize([$type, $props, $children, $extra])), 0, 8), 'props' => $props, 'children' => $children] + $extra;
    }

    /** @return list<string> */
    private function errors(array $tree): array
    {
        return $this->blocks()->validate($tree)[1];
    }

    private function described(string $name): array
    {
        foreach ($this->blocks()->describe() as $d) {
            if ($d['name'] === $name) {
                return $d;
            }
        }
        $this->fail("{$name} isn't registered");
    }

    public function testAHeadingShowsTheIconOfItsLevel(): void
    {
        $h = $this->described('core/heading');
        $this->assertSame('text-h', $h['icon'], 'the library shows a heading, of no level');
        $this->assertSame('level', $h['iconBy']['field']);
        $this->assertSame('text-h-two', ((array) $h['iconBy']['icons'])['2']);
        $this->assertNull($this->described('core/text')['iconBy']);
        $this->assertTrue(((array) $this->described('core/columns')['fields'][0]['options'])['summary'] ?? false, 'columns are summed up by their layout');
        try {
            BlockDefinition::make('acme/x')->iconBy('level', ['1' => '<svg>']);
            $this->fail('an icon name is checked');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('icon names', $e->getMessage());
        }
        try {
            BlockDefinition::make('acme/x')->iconBy('Level!', []);
            $this->fail('the field name is checked');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('field name', $e->getMessage());
        }
    }

    public function testNewBlocksArriveReadyToFill(): void
    {
        $starter = fn(string $name) => array_map(static fn($s) => $s['type'], $this->described($name)['starter']);
        $this->assertSame(['core/heading'], $starter('core/cover'));
        $this->assertSame(['core/heading', 'core/text'], $starter('core/media-text'));
        $this->assertSame(['core/button'], $starter('core/buttons'));
        $this->assertSame([], $starter('core/tabs'), 'tabs start with two tabs: the editor\'s rule for a block with one kind of child');

        $e = $this->assertThrows(\LogicException::class, static fn() => BlockDefinition::make('acme/box')->starter(['core/text']));
        $this->assertStringContainsString('call children() before starter()', $e->getMessage());
        $e = $this->assertThrows(\InvalidArgumentException::class, static fn() => BlockDefinition::make('acme/row')->children(['core/button'])->starter(['core/text']));
        $this->assertStringContainsString('it can\'t hold "core/text"', $e->getMessage());
    }

    public function testWhereEachBlockMayGo(): void
    {
        $this->assertSame([], $this->errors([
            self::node('core/cover', ['height' => 'tall'], [self::node('core/heading', ['text' => 'Fresh bread daily']), self::node('core/buttons', [], [self::node('core/button', ['text' => 'Order', 'link' => '/order'])])]),
            self::node('core/tabs', [], [self::node('core/tab', ['label' => 'Hours'], [self::node('core/text', ['text' => '<p>9 to 5</p>'])]), self::node('core/tab', ['label' => 'Where'])]),
        ]));
        $errors = $this->errors([
            self::node('core/tab', ['label' => 'Alone']),
            self::node('core/buttons', [], [self::node('core/text', ['text' => '<p>x</p>'])]),
            self::node('core/tabs', [], [self::node('core/tab', ['label' => '  '])]),
            self::node('core/media-text'),
            self::node('core/gallery', ['images' => []]),
        ]);
        $this->assertSame(5, count($errors), implode("\n", $errors));
        foreach (['Block 1 (Tab)', 'Block 2 (Buttons) › Block 1 (Text)', 'Block 3 (Tabs) › Block 1 (Tab), Tab name', 'Block 4 (Media and text), Image', 'Block 5 (Gallery), Images'] as $i => $start) {
            $this->assertStringStartsWith($start, $errors[$i]);
        }
    }

    public function testTablesAreCheckedAndTidied(): void
    {
        [$clean, $errors] = $this->blocks()->validate([self::node('core/table', ['table' => ['head' => true, 'rows' => [[' Day ', 'Open'], ['Mon', ' 9–17 ']]]])]);
        $this->assertSame([], $errors);
        $this->assertSame(['head' => true, 'rows' => [['Day', 'Open'], ['Mon', '9–17']]], ((array) $clean[0]['props'])['table']);

        $uneven = $this->errors([self::node('core/table', ['table' => ['rows' => [['a', 'b'], ['c']]]])]);
        $this->assertSame(['Block 1 (Table), Table: Row 2 has 1 cells; the first row has 2. Every row needs the same number.'], $uneven);
        $this->assertCount(1, $this->errors([self::node('core/table', ['table' => CoreListBlocks::BLANK_TABLE])]), 'an untouched table is empty: it needs some text');
        $this->assertCount(1, $this->errors([self::node('core/table', ['table' => ['rows' => [array_fill(0, 13, 'x')]]])]), 'at most 12 columns');
        $this->assertCount(1, $this->errors([self::node('core/table', ['table' => ['rows' => [[str_repeat('x', TableField::CELL_MAX + 1)]]]])]), 'cells have a length limit');

        $field = Field::table('t');
        $this->assertSame("Day | Open\nMon | 9–17", (new TableField())->text(['rows' => [['Day', 'Open'], ['Mon', '9–17']]], $field, $this->cms->content->fields));
    }

    public function testTabsLearnTheirNamesAndHiddenOnesGo(): void
    {
        $tree = [self::node('core/tabs', ['look' => 'pills'], [
            self::node('core/tab', ['label' => 'Menu'], [self::node('core/text', ['text' => '<p>Soup</p>'])]),
            self::node('core/tab', ['label' => 'Members'], [], ['visibility' => ['auth' => 'user']]),
            ['type' => 'core/tab', 'id' => 'tblank', 'props' => ['label' => ''], 'children' => []],      // stored without validation
        ])];
        $tree[0]['id'] = 'tabs1';
        $tabs = $this->blocks()->prepare($tree, new BlockContext())[0];
        $props = (array) $tabs['props'];
        $this->assertSame(['Menu'], $props['tabs'], 'a members-only tab is not sent to guests; a broken one is dropped, not the whole block');
        $this->assertSame([[0, 'tabs1']], array_map(static fn($p) => [$p['props']->index, $p['props']->group], $tabs['children']));
        $draft = (array) $this->blocks()->prepare($tree, new BlockContext(), null, draft: true)[0]['props'];
        $this->assertSame(['Menu', 'Tab 2'], $draft['tabs'], 'on the canvas a tab not named yet is shown with a number');
        $this->assertSame('<p>Soup</p>', $tabs['children'][0]['children'][0]['props']->text, 'what is inside a tab is prepared too');

        $hidden = [self::node('core/tabs', [], [self::node('core/tab', ['label' => 'Members'], [], ['visibility' => ['auth' => 'user']])])];
        $this->assertSame([], $this->blocks()->prepare($hidden, new BlockContext()), 'no tab to show: no tabs block');
    }

    public function testSocialLinksAreSafeAndNamed(): void
    {
        $unsafe = $this->errors([self::node('core/social', ['items' => [['network' => 'instagram', 'url' => 'javascript:alert(1)']]])]);
        $this->assertSame(['Block 1 (Social links), Links: Row 1, url: Use a path like /about, an http(s) address, mailto: or tel:.'], $unsafe);
        $unknown = $this->errors([self::node('core/social', ['items' => [['network' => 'myspace', 'url' => 'https://x.test']]])]);
        $this->assertCount(1, $unknown);
        $this->assertStringContainsString('Must be one of: facebook, instagram', $unknown[0]);

        $tree = [self::node('core/social', ['items' => [['network' => 'mastodon', 'url' => 'https://mastodon.social/@bakery'], ['network' => 'email', 'url' => 'mailto:hi@bakery.test']]])];
        $items = ((array) $this->blocks()->prepare($tree, new BlockContext())[0]['props'])['items'];
        $this->assertSame([
            ['network' => 'mastodon', 'url' => 'https://mastodon.social/@bakery', 'label' => 'Mastodon'],
            ['network' => 'email', 'url' => 'mailto:hi@bakery.test', 'label' => 'Email'],
        ], $items);
        $this->assertNull(CoreListBlocks::social(['props' => ['items' => [['network' => 'gone', 'url' => 'https://x.test']]]]), 'nothing left to link: the block goes');
    }

    public function testEveryIconNameHasADrawing(): void
    {
        foreach (['icons.js' => Icons::CONTENT, 'social-icons.js' => Icons::SOCIAL] as $file => $names) {
            $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/resources/js/shared/' . $file);
            preg_match_all("/^    '([a-z0-9-]+)': 'M[^']+',$/m", $js, $m);
            $this->assertSame(array_keys($names), $m[1], "{$file} draws exactly the icons Icons lists, in the same order");
        }
    }
}
