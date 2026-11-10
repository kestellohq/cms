<?php

declare(strict_types=1);

namespace Kestello\Tests\Seed;

use Kestello\Cms\Seed\SeedException;
use Kestello\Cms\Seed\SeedFolder;
use Kestello\Framework\Testing\TestCase;

/** Reading a seed folder: its shape, and messages that name the file to fix. */
final class SeedFolderTest extends TestCase
{
    /** @param array<string, string|array<mixed>> $files path => contents (arrays become JSON) */
    private function folder(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $path => $contents) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, is_array($contents) ? json_encode($contents) : $contents);
        }

        return $dir;
    }

    private function failsWith(string $message, string $dir): void
    {
        $e = $this->assertThrows(SeedException::class, static fn() => SeedFolder::read($dir));
        $this->assertStringContainsString($message, $e->getMessage());
    }

    public function testAFolderBecomesAPlan(): void
    {
        $dir = $this->folder([
            'site.json' => [
                'settings' => ['general' => ['site_name' => 'Acme', 'comments' => false]],
                'front_page' => 'home',
                'menus' => [['name' => 'Main', 'location' => 'primary', 'items' => [
                    ['page' => 'about'], ['url' => 'https://docs.acme.test/', 'label' => 'Docs'], ['archive' => 'post', 'label' => 'Blog'],
                ]]],
                'media' => ['team.jpg' => ['alt' => 'The team']],
            ],
            'pages/home.json' => ['title' => 'Home', 'blocks' => [['type' => 'core/image', 'props' => ['image' => '@media:team.jpg'], 'children' => []]]],
            'pages/about.json' => ['title' => 'About us', 'slug' => 'about-us', 'status' => 'draft', 'seo' => ['description' => 'Who we are']],
            'media/team.jpg' => 'jpeg bytes',
        ]);

        $plan = SeedFolder::read($dir);

        $this->assertSame(['about', 'home'], array_keys($plan['pages']), 'pages in file name order');
        $this->assertSame(['title' => 'About us', 'slug' => 'about-us', 'status' => 'draft', 'template' => null, 'seo' => ['description' => 'Who we are'], 'blocks' => []], $plan['pages']['about']);
        $this->assertSame('home', $plan['pages']['home']['slug'], 'the slug defaults to the file name');
        $this->assertSame('published', $plan['pages']['home']['status']);
        $this->assertSame('home', $plan['front_page']);
        $this->assertSame(['general' => ['site_name' => 'Acme', 'comments' => false]], $plan['settings'], 'values keep their type for the settings schema');
        $this->assertSame([['page' => 'about', 'label' => ''], ['url' => 'https://docs.acme.test/', 'label' => 'Docs'], ['archive' => 'post', 'label' => 'Blog']], $plan['menus'][0]['items']);
        $this->assertSame(['team.jpg'], array_keys($plan['media']));
        $this->assertSame('The team', $plan['media']['team.jpg']['alt']);
    }

    public function testAnEmptyFolderIsAnEmptyPlan(): void
    {
        $plan = SeedFolder::read($this->tempDir());

        $this->assertSame(['settings' => [], 'front_page' => null, 'menus' => [], 'media' => [], 'pages' => []], $plan);
    }

    public function testAMissingFolderIsNamed(): void
    {
        $this->failsWith('No seed folder at /nowhere/seed.', '/nowhere/seed');
    }

    public function testBrokenJsonNamesTheFile(): void
    {
        $this->failsWith('pages/home.json: not valid JSON', $this->folder(['pages/home.json' => '{"title": ']));
        $this->failsWith('site.json: must be a JSON object', $this->folder(['site.json' => '[1, 2]']));
    }

    public function testAPageNeedsATitleAndAGoodSlug(): void
    {
        $this->failsWith('pages/home.json: "title" is required.', $this->folder(['pages/home.json' => ['blocks' => []]]));
        $this->failsWith('pages/home.json: "slug" must be lowercase', $this->folder(['pages/home.json' => ['title' => 'Home', 'slug' => 'Home Page']]));
        $this->failsWith('pages/Home.json: name the file', $this->folder(['pages/Home.json' => ['title' => 'Home']]));
        $this->failsWith('pages/b.json: the slug "same" is also used by pages/a.json.', $this->folder([
            'pages/a.json' => ['title' => 'A', 'slug' => 'same'], 'pages/b.json' => ['title' => 'B', 'slug' => 'same'],
        ]));
        $this->failsWith('"status" must be published or draft', $this->folder(['pages/home.json' => ['title' => 'Home', 'status' => 'trash']]));
    }

    public function testUnknownFieldsAreNamedNotIgnored(): void
    {
        $this->failsWith('pages/home.json: unknown "body"; use title, slug', $this->folder(['pages/home.json' => ['title' => 'Home', 'body' => []]]));
        $this->failsWith('site.json: unknown "frontpage"', $this->folder(['site.json' => ['frontpage' => 'home']]));
    }

    public function testTheFrontPageIsAPageOfTheSeed(): void
    {
        $this->failsWith('"front_page" must be the key of a page', $this->folder(['site.json' => ['front_page' => 'home']]));
        $this->failsWith('not settings.reading.front_page', $this->folder(['site.json' => ['settings' => ['reading' => ['front_page' => '4']]]]));
    }

    public function testMenuItemsLinkToSomething(): void
    {
        $menu = static fn(array $item): array => ['site.json' => ['menus' => [['name' => 'Main', 'items' => [$item]]]]];
        $this->failsWith('menus[0].items[0]: "page" must be the key of a page', $this->folder($menu(['page' => 'nowhere'])));
        $this->failsWith('menus[0].items[0]: a link needs a "url" and a "label"', $this->folder($menu(['url' => 'https://acme.test/'])));
        $this->failsWith('menus[0].items[0]: say what it links to', $this->folder($menu(['label' => 'Home'])));
        $this->failsWith('menus[0] needs a "name"', $this->folder(['site.json' => ['menus' => [['items' => []]]]]));
        $this->failsWith('menus[0].items[0].children[1]: "page" must be the key of a page', $this->folder($menu(['url' => '/a', 'label' => 'A', 'children' => [['url' => '/b', 'label' => 'B'], ['page' => 'nowhere']]])));
        $this->failsWith('menus[0].items[0].children must be a list', $this->folder($menu(['url' => '/a', 'label' => 'A', 'children' => ['page' => 'x']])));
    }

    public function testMediaReferencesNameRealFiles(): void
    {
        $this->failsWith('pages/home.json: "@media:gone.jpg" names a file that is not in media/', $this->folder([
            'pages/home.json' => ['title' => 'Home', 'seo' => ['image' => '@media:gone.jpg']],
        ]));
        $this->failsWith('media "gone.jpg" has alt text but there is no media/gone.jpg', $this->folder(['site.json' => ['media' => ['gone.jpg' => ['alt' => 'x']]]]));
    }

    public function testReferencesAreFoundAnywhereInATree(): void
    {
        $tree = [['type' => 'a', 'props' => ['image' => '@media:a.jpg'], 'children' => [['props' => ['items' => [['img' => '@media:b.png']]]]]], ['props' => ['image' => '@media:a.jpg']]];

        $this->assertSame(['a.jpg', 'b.png'], SeedFolder::references($tree));
    }
}
