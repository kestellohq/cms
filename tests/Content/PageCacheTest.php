<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Cache\PageCacheModule;
use Kestello\Cms\Cache\PageTags;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Frontend\Csp;
use Kestello\Cms\Site\SiteModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** The page cache: what is cached, tag purges, nonces, ETags. */
final class PageCacheTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->cms->container->instance(\Kestello\Contracts\Cache\Store::class, new \Kestello\Framework\Cache\ArrayStore());
        foreach ([new PageCacheModule(), new ContentModule(), new SiteModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'eve', 'email' => 'eve@example.test', 'role' => 'editor']);
        $this->signIn('ana');
        $this->settings(['performance' => ['page_cache' => true]]);

        // two pages showing one entry each, and one that misbehaves in each way
        $page = static function (string $tag, string $body = 'page') {
            return static function () use ($tag, $body): Response {
                PageTags::add($tag);
                $nonce = \Kestello\Cms\Cms::instance()->container->make(Csp::class)->nonce();

                return new Response(
                    "<script nonce=\"{$nonce}\">{$body} " . bin2hex(random_bytes(4)) . '</script>',
                    200,
                    ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => "script-src 'nonce-{$nonce}'"],
                );
            };
        };
        $this->cms->routes->get('/_one', $page('entry:1'));
        $this->cms->routes->get('/_two', $page('entry:2'));
        $this->cms->routes->get('/_cookie', static fn() => (new Response('x', 200, ['Content-Type' => 'text/html']))->withCookie('seen', '1'));
        $this->cms->routes->get('/_private', static fn() => new Response('x', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']));
        $this->cms->routes->get('/_download', static fn() => new Response('x', 200, ['Content-Type' => 'application/zip']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function signIn(string $login): void
    {
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private function settings(array $groups): void
    {
        $r = $this->api('PATCH', '/settings', $groups);
        $this->assertSame(200, $r->statusCode(), $r->content());
    }

    /** A visitor's request: a fresh CSP nonce each time, like a real request. */
    private function visit(string $uri, array $headers = [], array $cookies = []): Response
    {
        $this->nextRequest();
        $this->cms->container->instance(Csp::class, new Csp($this->cms->filters));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->cms->routes->dispatch(new Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), $headers, $query, [], $cookies, [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    private function state(string $uri, array $headers = [], array $cookies = []): ?string
    {
        return $this->visit($uri, $headers, $cookies)->headers()['X-Kestello-Cache'] ?? null;
    }

    public function testGuestPagesAreServedFromTheCache(): void
    {
        $first = $this->visit('/_one');
        $second = $this->visit('/_one');
        $this->assertSame(['MISS', 'HIT'], [$first->headers()['X-Kestello-Cache'], $second->headers()['X-Kestello-Cache']]);

        preg_match('/nonce="([^"]+)"/', $first->content(), $a);
        preg_match('/nonce="([^"]+)"/', $second->content(), $b);
        $this->assertFalse($a[1] === $b[1], 'every hit gets its own nonce');
        $this->assertSame(preg_replace('/nonce="[^"]+"/', '', $first->content()), preg_replace('/nonce="[^"]+"/', '', $second->content()), 'and the same page');
        $this->assertSame("script-src 'nonce-{$b[1]}'", $second->headers()['Content-Security-Policy'], 'the policy matches the page');

        $etag = $second->headers()['ETag'];
        $this->assertSame('public, max-age=0, must-revalidate', $second->headers()['Cache-Control']);
        $notModified = $this->visit('/_one', ['If-None-Match' => $etag]);
        $this->assertSame([304, ''], [$notModified->statusCode(), $notModified->content()]);

        $this->assertSame('HIT', $this->state('/_one?utm_source=news&fbclid=x'), 'campaign parameters are the same page');
        $this->assertNull($this->state('/_one?sort=new'), 'unknown parameters are never stored');
        $this->assertNull($this->state('/_one', cookies: ['kestello_session' => 'abc']), 'signed-in visitors get their own pages');
        $this->assertNull($this->state('/_one', ['Authorization' => 'Bearer x']));
        foreach (['/_cookie', '/_private', '/_download', '/nothing-here'] as $uncacheable) {
            $this->visit($uncacheable);
            $this->assertNull($this->state($uncacheable), "{$uncacheable} is never stored");
        }

        $this->settings(['performance' => ['cdn_max_age' => 120]]);
        $this->visit('/_one');
        $this->assertSame('public, max-age=0, must-revalidate, s-maxage=120, stale-while-revalidate=1200', $this->visit('/_one')->headers()['Cache-Control']);

        $this->settings(['performance' => ['page_cache' => false]]);
        $this->assertNull($this->state('/_one'), 'switched off');
    }

    public function testChangesPurgeExactlyTheirPages(): void
    {
        $feed = fn() => $this->visit('/feed');
        foreach (['/_one', '/_two', '/feed'] as $uri) {
            $this->visit($uri);
            $this->assertSame('HIT', $this->state($uri));
        }

        $entry = $this->json($this->api('POST', '/content/post/entries', ['title' => 'Fresh bread', 'status' => 'published']))['data'];
        $this->assertSame('MISS', $feed()->headers()['X-Kestello-Cache'], 'a new post refreshes the feed');
        $this->assertStringContainsString('Fresh bread', $feed()->content());
        $this->assertSame('HIT', $this->state('/_two'), 'pages that don\'t show it stay cached');

        PageTags::reset();
        $this->cms->routes->get('/_fresh', function () use ($entry) {
            PageTags::add('entry:' . $entry['id']);
            return new Response('fresh', 200, ['Content-Type' => 'text/html']);
        });
        $this->visit('/_fresh');
        $this->assertSame('HIT', $this->state('/_fresh'));
        $this->api('PATCH', "/content/post/entries/{$entry['id']}", ['title' => 'Fresher bread']);
        $this->assertSame('MISS', $this->state('/_fresh'), 'editing an entry refreshes its page');
        $this->assertSame('HIT', $this->state('/_two'));

        $this->settings(['general' => ['site_name' => 'Bakery']]);
        $this->assertSame('MISS', $this->state('/_two'), 'settings show on every page: everything goes');

        $this->visit('/_two');
        $this->signIn('eve');
        $this->assertSame(403, $this->api('POST', '/system/page-cache/clear')->statusCode(), 'editors can\'t change settings, so they can\'t clear it either');
        $this->signIn('ana');
        $this->assertSame(200, $this->api('POST', '/system/page-cache/clear')->statusCode());
        $this->assertSame('MISS', $this->state('/_two'));
    }

    public function testChildPagesFollowTheirParents(): void
    {
        $about = $this->json($this->api('POST', '/content/page/entries', ['title' => 'About', 'status' => 'draft']))['data'];
        // (test pages render without a theme, so they carry no Content-Type and are not stored; the tags are what matter)
        $this->api('POST', '/content/page/entries', ['title' => 'Team', 'status' => 'published', 'parent_id' => $about['id']]);
        $this->assertSame(404, $this->visit('/about/team')->statusCode(), 'a draft parent hides its children');

        $this->api('PATCH', "/content/page/entries/{$about['id']}", ['status' => 'published']);
        $this->assertSame(200, $this->visit('/about/team')->statusCode());
        $this->assertTrue(in_array('entry:' . $about['id'], PageTags::all(), true), 'the child page is tagged with its parent, so changing the parent purges it');
        $this->api('PATCH', "/content/page/entries/{$about['id']}", ['status' => 'draft']);
        $this->assertSame(404, $this->visit('/about/team')->statusCode(), 'unpublishing the parent purges the child page');
    }

    public function testPagesThatChangeByThemselvesOrWhileRenderingAreNotKeptStale(): void
    {
        $this->cms->routes->get('/_now', static function (): Response {
            PageTags::add('entry:1');
            PageTags::until(time());            // a block ends right now
            return new Response('x', 200, ['Content-Type' => 'text/html']);
        });
        $this->visit('/_now');
        $this->assertTrue($this->state('/_now') !== 'HIT', 'a page whose content changes now is not cached');

        $this->cms->routes->get('/_racing', function (): Response {
            PageTags::add('entry:7');
            $this->cms->container->make(\Kestello\Contracts\Cache\PageCache::class)->purge('entry:7');   // saved while rendering
            return new Response('x', 200, ['Content-Type' => 'text/html']);
        });
        $this->visit('/_racing');
        $this->assertTrue($this->state('/_racing') !== 'HIT', 'a render that a save overtook is not kept as fresh');
    }
}
