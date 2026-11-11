<?php

declare(strict_types=1);

namespace Kestello\Tests\Seed;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Menus\Menus;
use Kestello\Cms\Seed\SeedException;
use Kestello\Cms\Seed\SeedFolder;
use Kestello\Cms\Seed\SiteSeeder;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/** Putting a seed folder into a site: what it creates, and that a bad seed writes nothing. */
final class SiteSeederTest extends AuthTestCase
{
    /** A 1 by 1 pixel PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $storage = '';

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->storage = $this->tempDir();
        $this->cms->storage->configure($this->storage, []);
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $this->cms->menus->location('primary', 'Main navigation');
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

    /** @return list<string> every file in the site's storage */
    private function stored(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr((string) $file, strlen($this->storage));
        }

        return $files;
    }

    /** @param array<string, string|array<mixed>> $files */
    private function folder(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $path => $contents) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, is_array($contents) ? json_encode($contents) : $contents);
        }

        return $dir;
    }

    /** @return array<string, string|array<mixed>> */
    private function site(): array
    {
        $heading = static fn(string $text): array => ['type' => 'core/heading', 'props' => ['text' => $text, 'level' => '2'], 'children' => []];

        return [
            'site.json' => [
                'settings' => ['general' => ['site_name' => 'Acme'], 'seo' => ['seo_publisher_logo' => '@media:dot.png']],
                'front_page' => 'home',
                'menus' => [
                    ['name' => 'Main', 'location' => 'primary', 'items' => [['page' => 'home'], ['page' => 'about', 'label' => 'Who we are'], ['url' => 'https://docs.acme.test/', 'label' => 'Docs', 'children' => [['url' => '/guides', 'label' => 'Guides', 'children' => [['page' => 'home', 'label' => 'Start']]]]]]],
                    ['name' => 'Legal', 'location' => 'legal', 'items' => [['page' => 'about']]],
                ],
                'media' => ['dot.png' => ['alt' => 'A dot']],
            ],
            'pages/home.json' => ['title' => 'Home', 'blocks' => [$heading('Welcome'), ['type' => 'core/image', 'props' => ['image' => '@media:dot.png'], 'children' => []]]],
            'pages/about.json' => ['title' => 'About us', 'slug' => 'about-us', 'blocks' => [$heading('About')]],
            'media/dot.png' => (string) base64_decode(self::PNG),
        ];
    }

    public function testASeedFillsAnEmptySite(): void
    {
        $dir = $this->folder($this->site());

        $summary = (new SiteSeeder($this->cms))->seed(SeedFolder::read($dir), $this->author());

        $this->assertSame(['media' => 1, 'pages' => 2, 'menus' => 2, 'settings' => 2], array_intersect_key($summary, array_flip(['media', 'pages', 'menus', 'settings'])));
        $this->assertSame(['Legal'], $summary['unassigned'], 'the theme has no "legal" location');

        $home = Entry::query()->find($summary['front_page']);
        $this->assertSame(['Home', 'home', 'published'], [(string) $home?->title, (string) $home?->slug, (string) $home?->status]);
        $media = (int) $this->db->table('media')->value('id');
        $this->assertSame($media, (int) $home?->field('body')[1]['props']['image'], '"@media:dot.png" became the file\'s id');
        $this->assertSame('A dot', (string) $this->db->table('media')->value('alt'));
        $this->assertFileExists($dir . '/media/dot.png', 'the seed folder is left as it was');

        $this->assertSame('About us', (string) Entry::query()->where('slug', 'about-us')->first()?->title);

        $settings = Settings::getInstance();
        $this->assertSame(['Acme', (string) $summary['front_page']], [$settings->get('site_name'), $settings->get('front_page')]);
        $this->assertSame($media, (int) $settings->get('seo_publisher_logo'), 'a setting can name a file too');
        $primary = $this->cms->container->make(Menus::class)->forLocations()['primary'];
        $this->assertSame(['Home', 'Who we are', 'Docs'], array_column($primary, 'label'));
        $this->assertSame('Guides', $primary[2]['children'][0]['label'] ?? null, 'menus nest');
        $this->assertSame('Start', $primary[2]['children'][0]['children'][0]['label'] ?? null, 'a page link nests too');
    }

    public function testSettingsAreCheckedLikeTheSettingsScreen(): void
    {
        $files = $this->site();
        $files['site.json']['settings'] = [
            'general' => ['site_name' => str_repeat('a', 500), 'nope' => 'x'],
            'mail' => ['smtp_password' => 'hunter2'],
            'nothing' => ['x' => '1'],
        ];
        $plan = SeedFolder::read($this->folder($files));

        $problems = (new SiteSeeder($this->cms))->check($plan, $this->author());

        $this->assertCount(4, $problems, implode(' | ', $problems));
        $general = implode(' | ', array_slice($problems, 0, 2));
        $this->assertStringContainsString('site.json: settings.general.site_name: ', $general, 'too long');
        $this->assertStringContainsString('site.json: settings.general.nope: ', $general, 'not a setting');
        $this->assertSame('site.json: settings.mail.smtp_password is a secret and does not belong in a seed; set it on the site.', $problems[2]);
        $this->assertStringStartsWith('site.json: there are no "nothing" settings; use general, ', $problems[3]);
        $this->assertSame(0, $this->db->table('entries')->count());
    }

    public function testASiteWithContentIsLeftAlone(): void
    {
        $seeder = new SiteSeeder($this->cms);
        $seeder->seed(SeedFolder::read($this->folder($this->site())), $this->author());

        $e = $this->assertThrows(SeedException::class, fn() => $seeder->seed(SeedFolder::read($this->folder($this->site())), $this->author()));

        $this->assertStringContainsString('only goes into an empty site', $e->getMessage());
        $this->assertStringContainsString('--update', $e->getMessage(), 'and says how to update it instead');
        $this->assertSame(2, $this->db->table('entries')->count());
    }

    public function testUpdateBringsASiteInLineWithTheSeed(): void
    {
        $seeder = new SiteSeeder($this->cms);
        $first = $seeder->seed(SeedFolder::read($this->folder($this->site())), $this->author());
        $about = (int) Entry::query()->where('slug', 'about-us')->first()?->getKey();
        $type = $this->cms->content->get('page');
        $this->assertNotNull($type);
        $mine = (int) $this->cms->container->make(\Kestello\Cms\Content\Entries::class)->create($type, ['title' => 'Written by hand', 'status' => 'published'], $this->author())->getKey();

        // the seed changes: new words, a new page, a new file, a menu with other items
        $files = $this->site();
        $files['pages/home.json']['blocks'][0]['props']['text'] = 'Welcome back';
        $files['pages/about.json']['title'] = 'About the team';
        $files['pages/contact.json'] = ['title' => 'Contact', 'blocks' => [['type' => 'core/image', 'props' => ['image' => '@media:map.png'], 'children' => []]]];
        $files['media/map.png'] = (string) base64_decode(self::PNG) . "\0";
        $files['site.json']['menus'][0]['items'] = [['page' => 'contact']];
        $dir = $this->folder($files);

        $this->assertSame([], $seeder->check(SeedFolder::read($dir), $this->author(), update: true), 'a dry run of an update finds nothing wrong');
        $summary = $seeder->seed(SeedFolder::read($dir), $this->author(), update: true);

        $this->assertSame([4, 1, 0, 1, 1], [$summary['updated'], $summary['pages'], $summary['menus'], $summary['media'], $summary['reused']], 'updated, new pages, new menus, new files, files kept');
        $this->assertSame($first['front_page'], $summary['front_page'], 'the home page is the same entry');
        $this->assertSame('Welcome back', Entry::query()->find($summary['front_page'])?->field('body')[0]['props']['text'] ?? null);
        $this->assertSame('About the team', (string) Entry::query()->find($about)?->title, 'updated in place');
        $this->assertSame('Written by hand', (string) Entry::query()->find($mine)?->title, 'pages the seed does not name are left alone');
        $this->assertSame([4, 2, 2], [$this->db->table('entries')->count(), $this->db->table('media')->count(), $this->db->table('menus')->count()]);
        $this->assertSame(['Contact'], array_column($this->cms->container->make(Menus::class)->forLocations()['primary'], 'label'));

        $again = $seeder->seed(SeedFolder::read($dir), $this->author(), update: true);
        $this->assertSame([0, 2, 5], [$again['media'], $again['reused'], $again['updated']], 'a second update adds nothing');
        $this->assertSame([4, 2, 2], [$this->db->table('entries')->count(), $this->db->table('media')->count(), $this->db->table('menus')->count()]);
    }

    public function testCheckingNamesEveryBadBlockAndWritesNothing(): void
    {
        $files = $this->site();
        $files['pages/about.json'] = ['title' => 'About', 'blocks' => [['type' => 'acme/nothing', 'props' => [], 'children' => []]]];
        $files['pages/home.json']['blocks'][] = ['type' => 'core/heading', 'props' => ['level' => '9'], 'children' => []];
        $plan = SeedFolder::read($this->folder($files));
        $seeder = new SiteSeeder($this->cms);

        $problems = $seeder->check($plan, $this->author());

        $this->assertCount(3, $problems, implode(' | ', $problems));
        $this->assertStringStartsWith('pages/about.json: ', $problems[0]);
        $this->assertStringContainsString('acme/nothing', $problems[0]);
        $this->assertStringStartsWith('pages/home.json: Block 3 (Heading)', $problems[1]);
        $this->assertStringStartsWith('pages/home.json: Block 3 (Heading)', $problems[2]);
        $this->assertSame([0, 0, 0], [$this->db->table('entries')->count(), $this->db->table('media')->count(), $this->db->table('menus')->count()]);
        $this->assertThrows(SeedException::class, fn() => $seeder->seed($plan, $this->author()));
    }

    public function testAGoodCheckWritesNothing(): void
    {
        $problems = (new SiteSeeder($this->cms))->check(SeedFolder::read($this->folder($this->site())), $this->author());

        $this->assertSame([], $problems);
        $this->assertSame([0, 0, 0], [$this->db->table('entries')->count(), $this->db->table('media')->count(), $this->db->table('menus')->count()]);
        $this->assertSame(null, Settings::getInstance()->get('site_name'));
        $this->assertSame([], $this->stored(), 'the media files are deleted again: ' . implode(', ', $this->stored()));
    }

    public function testAFailureHalfwayLeavesTheSiteEmpty(): void
    {
        $files = $this->site();
        // Blocks pass the check; the menu fails when it is saved, after the media and pages.
        $files['site.json']['menus'][0]['name'] = str_repeat('m', 101);
        $plan = SeedFolder::read($this->folder($files));

        $e = $this->assertThrows(SeedException::class, fn() => (new SiteSeeder($this->cms))->seed($plan, $this->author()));

        $this->assertStringContainsString('site.json: menu "mmm', $e->getMessage());
        $this->assertStringContainsString('at most 100 characters', $e->getMessage());
        $this->assertSame([0, 0, 0], [$this->db->table('entries')->count(), $this->db->table('media')->count(), $this->db->table('menus')->count()]);
        $this->assertSame(null, Settings::getInstance()->get('site_name'));
        $this->assertSame([], $this->stored(), 'the media files are deleted again: ' . implode(', ', $this->stored()));
    }
}
