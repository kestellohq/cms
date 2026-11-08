<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Demo\DemoContent;
use Kestello\Cms\Forms\FormsModule;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Menus\Menus;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/** The public demo's sample bakery: what seeding an empty site creates. */
final class DemoContentTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->cms->storage->configure($this->tempDir(), []);
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule(), new FormsModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $this->cms->menus->location('primary', 'Main navigation');     // as the Base theme declares them
        $this->cms->menus->location('footer', 'Footer links');
        ContentModule::finalize($this->cms);
        $this->createUser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function author(): Principal
    {
        return $this->cms->auth->principalFor((array) $this->db->table('users')->where('username', 'ana')->first(), 'cli');
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();

        return $this->cms->routes->dispatch(new Request('GET', $uri, $uri, [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    /** @return list<string> titles of published entries of a type */
    private function titles(string $type): array
    {
        return array_map('strval', $this->db->table('entries')->where('type', $type)->where('status', 'published')->orderBy('id')->pluck('title'));
    }

    public function testSeedingFillsAnEmptySiteWithABakery(): void
    {
        $summary = DemoContent::seed($this->cms, $this->author());

        $this->assertSame(['Home', 'About', 'Visit us', 'Contact'], $this->titles('page'));
        $this->assertSame(5, count($this->titles('post')));
        $this->assertSame(['pages' => 4, 'posts' => 5, 'terms' => 7, 'forms' => 1, 'menus' => 2, 'images' => 5], array_diff_key($summary, ['front_page' => 0]));

        $settings = Settings::getInstance();
        $this->assertSame('Larkspur Bakery', $settings->get('site_name'));
        $this->assertSame('1', $settings->get('discourage_search'));
        $home = Entry::query()->find($summary['front_page']);
        $this->assertSame(['Home', (string) $summary['front_page']], [(string) $home?->title, $settings->get('front_page')]);

        $this->assertSame(['News', 'Recipes'], array_map('strval', $this->db->table('terms')->where('taxonomy', 'category')->orderBy('id')->pluck('name')));
        $this->assertSame(5, $this->db->table('terms')->where('taxonomy', 'tag')->count());
        $this->assertSame(5, $this->db->table('entry_term')->where('taxonomy', 'category')->count(), 'every post has a category');
        $dates = $this->db->table('entries')->where('type', 'post')->pluck('published_at');
        $this->assertTrue(max($dates) < date('Y-m-d H:i:s') && min($dates) < max($dates), 'published over the past weeks');
        foreach ($this->db->table('entries')->where('type', 'post')->get() as $post) {
            $this->assertTrue((int) (json_decode((string) $post['data'], true)['image'] ?? 0) > 0, "{$post['title']} has a featured image");
        }

        $form = $this->db->table('forms')->first();
        $this->assertSame('Contact', $form['title'] ?? null);
        $this->assertSame(['name', 'email', 'message'], array_column(json_decode((string) $form['definition'], true)['fields'], 'name'));
        $contact = json_decode((string) $this->db->table('entries')->where('slug', 'contact')->value('data'), true);
        $this->assertSame(['core/form', (string) $form['id']], [$contact['body'][1]['type'], ((array) $contact['body'][1]['props'])['form']], 'the contact page shows the form');

        $menus = $this->cms->container->make(Menus::class);
        $assigned = $menus->assignments();
        $this->assertTrue($assigned['primary'] !== null && $assigned['footer'] !== null && $assigned['primary'] !== $assigned['footer']);
        $this->assertSame(['Home', 'About', 'Visit us', 'Blog', 'Contact'], array_column($menus->forLocations()['primary'], 'label'));

        $this->assertSame(5, $this->db->table('media')->count());
        foreach ($this->db->table('media')->get() as $file) {
            $this->assertTrue((int) $file['size'] < 150_000 && (int) $file['width'] === 960, "{$file['filename']} is a small 960 pixel image");
        }

        $page = $this->visit('/')->payload();
        $this->assertSame('@core/content/Entry', $page?->component, 'the home page renders at /');
        $body = $page->props['entry']['data']->body;
        $this->assertSame('core/cover', $body[0]['type']);
        $this->assertTrue(((array) $body[0]['props'])['image']['id'] > 0, 'the hero shows a generated image');
        $this->assertSame(200, $this->visit('/contact')->statusCode());
        $this->assertSame(200, $this->visit('/blog')->statusCode());
    }

    public function testWithoutImagesEverythingElseIsStillCreated(): void
    {
        $summary = DemoContent::seed($this->cms, $this->author(), images: false);

        $this->assertSame([4, 5, 0], [$summary['pages'], $summary['posts'], $summary['images']]);
        $this->assertSame(0, $this->db->table('media')->count());
        $home = (array) Entry::query()->find($summary['front_page'])?->data;
        $types = array_column($home['body'], 'type');
        $this->assertSame('core/cover', $types[0], 'the hero keeps its colour');
        $this->assertNotContains('core/image', $types);
        $this->assertNotContains('core/media-text', $types, 'its text stays, without the image beside it');
        $this->assertStringContainsString('Made by hand, in small batches', json_encode($home['body'], JSON_THROW_ON_ERROR));
        $this->assertNotNull($this->visit('/')->payload());
    }

    public function testASiteWithContentIsLeftAlone(): void
    {
        DemoContent::seed($this->cms, $this->author(), images: false);

        $this->assertThrows(\RuntimeException::class, fn() => DemoContent::seed($this->cms, $this->author(), images: false));
        $this->assertSame(4, $this->db->table('entries')->where('type', 'page')->count());
    }

    public function testImagesAreSmallFlatJpegs(): void
    {
        $this->assertTrue(extension_loaded('gd'), 'the test image needs GD');
        $jpeg = DemoContent::paint(['background' => '#f3e6cf', 'shapes' => [['ellipse', [480, 320, 300, 200], '#b5763a'], ['line', [0, 0, 960, 640, 8], '#7d4a22']]]);

        $this->assertSame([960, 640, IMAGETYPE_JPEG], array_slice((array) getimagesizefromstring($jpeg), 0, 3));
        $this->assertTrue(strlen($jpeg) < 150_000);
        $this->assertThrows(\RuntimeException::class, fn() => DemoContent::paint(['background' => '#000000', 'shapes' => [['star', [1, 2], '#ffffff']]]));
    }
}
