<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Redirects\RedirectsModule;
use Kestello\Cms\Site\SiteModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Address patterns, date and author archives, featured images in lists. */
final class ArchivesTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new ContentModule(), new MediaModule(), new RedirectsModule(), new SiteModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $this->settings(['general' => ['site_url' => 'https://bakery.test', 'timezone' => 'Pacific/Auckland']]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function settings(array $groups): void
    {
        $r = $this->api('PATCH', '/settings', $groups);
        $this->assertSame(200, $r->statusCode(), $r->content());
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->cms->routes->dispatch(new Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), [], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    private function goesTo(string $uri): array
    {
        $r = $this->visit($uri);

        return [$r->statusCode(), $r->headers()['Location'] ?? null];
    }

    private function post(array $body): array
    {
        $r = $this->api('POST', '/content/post/entries', $body + ['status' => 'published']);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** @return list<string> entry titles on a list page */
    private function titles(string $uri): array
    {
        $page = $this->visit($uri)->payload();
        $this->assertNotNull($page, $uri);

        return array_column($page->props['entries'], 'title');
    }

    public function testAddressPatternsNeverBreakLinks(): void
    {
        // 15:00 UTC on 28 February is 1 March in Auckland: dates in addresses use the site's clock
        $this->post(['title' => 'Hello', 'published_at' => '2026-02-28T15:00:00Z']);
        $this->assertSame(200, $this->visit('/blog/hello')->statusCode());

        $this->settings(['reading' => ['permalink_post' => '{year}/{month}/{slug}']]);
        $this->assertSame(200, $this->visit('/blog/2026/03/hello')->statusCode());
        $this->assertSame(200, $this->visit('/blog/2026/03/hello?ref=mail')->statusCode());
        $this->assertSame([301, '/blog/2026/03/hello'], $this->goesTo('/blog/hello'), 'links from before the change still work');
        $this->assertSame([301, '/blog/2026/03/hello?ref=mail'], $this->goesTo('/blog/hello?ref=mail'));
        $this->assertSame([301, '/blog/2026/03/hello'], $this->goesTo('/blog/2026/02/hello'), 'a wrong date, the same post');
        $this->assertSame([404, null], $this->goesTo('/blog/news/hello'), 'only date-shaped prefixes');
        $this->assertSame([404, null], $this->goesTo('/blog/2026/03/nothing'));

        $feed = simplexml_load_string($this->visit('/feed')->content());
        $this->assertSame('https://bakery.test/blog/2026/03/hello', (string) $feed->channel->item[0]->link, 'feeds and sitemaps follow the pattern');
        $this->assertSame('/blog/2026/03/hello', $this->visit('/blog')->payload()->props['entries'][0]['path']);

        $this->settings(['reading' => ['permalink_post' => '{year}/{slug}']]);
        $this->assertSame([301, '/blog/2026/hello'], $this->goesTo('/blog/2026/03/hello'), 'and again after another change');

        $twelve = $this->post(['title' => '12', 'published_at' => '2026-03-05T09:00:00Z']);
        $this->assertSame('12', $twelve['slug']);
        $this->assertSame('12', $this->visit('/blog/2026/12')->payload()->props['entry']['title'] ?? null, 'a two-digit slug is the post, not December');
        $this->assertTrue(in_array('12', $this->titles('/blog/2026/03'), true), 'and the March archive lists it');
        $this->assertFalse('2026' === $this->post(['title' => '2026'])['slug'], 'a post can\'t take a year\'s address');
        $this->assertSame(422, $this->api('PATCH', '/settings', ['reading' => ['permalink_post' => '{slug}/{id}']])->statusCode());
    }

    public function testDateAndAuthorArchives(): void
    {
        $this->post(['title' => 'Winter', 'published_at' => '2025-12-31T12:00:00Z']);      // 1:00 on 1 January 2026 in Auckland
        $this->post(['title' => 'Spring', 'published_at' => '2026-03-10T09:00:00Z']);
        $this->post(['title' => 'Later', 'published_at' => '2026-03-20T09:00:00Z']);

        $this->assertSame(['Later', 'Spring', 'Winter'], $this->titles('/blog/2026'));
        $this->assertSame(['Later', 'Spring'], $this->titles('/blog/2026/03'));
        $this->assertSame(['Winter'], $this->titles('/blog/2026/01'));
        $page = $this->visit('/blog/2026/03')->payload();
        $this->assertSame(['March 2026', '/blog/2026/03'], [$page->props['type']['plural'], $page->props['type']['path']]);
        foreach (['/blog/2026/04', '/blog/2025', '/blog/1969', '/blog/2026/13'] as $empty) {
            $this->assertSame(404, $this->visit($empty)->statusCode(), "{$empty}: nothing there, no page");
        }

        $this->assertSame(['Later', 'Spring', 'Winter'], $this->titles('/author/ana'));
        $this->assertSame('/author/ana', $this->visit('/blog/later')->payload()->props['entry']['author']['path'], 'bylines link to the author');
        $this->assertSame(404, $this->visit('/author/nobody')->statusCode());
        $this->createUser(['username' => 'quiet', 'email' => 'quiet@example.test', 'role' => 'author']);
        $this->assertSame(404, $this->visit('/author/quiet')->statusCode(), 'someone who published nothing has no public page');
    }

    public function testListsShowFeaturedImages(): void
    {
        $png = (function (): string {
            ob_start();
            imagepng(imagecreatetruecolor(12, 8));
            return (string) ob_get_clean();
        })();
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'loaf.png', 'size' => strlen($png)]))['data'];
        $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $png);
        $media = $this->json($this->api('POST', "/media/uploads/{$up['id']}/complete"))['data'];

        $this->post(['title' => 'With picture', 'data' => ['image' => $media['id']], 'published_at' => '2026-03-02T09:00:00Z']);
        $this->post(['title' => 'Without', 'published_at' => '2026-03-01T09:00:00Z']);
        $entries = $this->visit('/blog')->payload()->props['entries'];
        $this->assertSame([$media['id'], 12, 8], [$entries[0]['image']['id'], $entries[0]['image']['width'], $entries[0]['image']['height']]);
        $this->assertNull($entries[1]['image']);
        $this->assertSame($media['id'], $this->visit('/author/ana')->payload()->props['entries'][0]['image']['id'], 'every list, not just the archive');
    }
}
