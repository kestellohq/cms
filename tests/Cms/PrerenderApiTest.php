<?php

declare(strict_types=1);

namespace Tests\Cms;

use Kestello\Cms\Frontend\FrontendModule;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\Prerender\PrerenderStore;
use Kestello\Contracts\Cache\PageCache;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * GET /system/prerender and POST /system/prerender/{key}: the pages waiting
 * for their saved HTML, and saving it. Administrators only.
 */
final class PrerenderApiTest extends AuthTestCase
{
    public function testAdministratorsListWaitingPagesAndSaveTheirHtml(): void
    {
        $store = $this->bindStore();
        $purged = $this->bindPageCache();
        $key = hash('sha256', 'about');
        $store->want($key, ['url' => '/about', 'page' => ['component' => 'x', 'props' => [], 'url' => '/about', 'version' => 'v'], 'modules' => ['x' => '/system/prerender/modules/core/x.mjs'], 'messages' => []]);
        $this->createUser();
        $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'role' => 'user']);

        $this->signIn('bo');
        $this->assertSame(403, $this->api('GET', '/system/prerender')->statusCode());
        $this->assertSame(403, $this->api('POST', "/system/prerender/{$key}", ['html' => '<p>x</p>'])->statusCode());
        $this->api('DELETE', '/auth/session');

        $this->signIn('ana');
        $list = $this->json($this->api('GET', '/system/prerender?limit=10'))['data'];
        $this->assertTrue($list['enabled']);
        $this->assertSame(1, $list['waiting']);
        $this->assertSame([$key], array_column($list['pages'], 'key'));

        // HTML that pages never hold is refused, and that page stops waiting (the browser keeps drawing it)
        $contact = hash('sha256', 'contact');
        $store->want($contact, ['url' => '/contact', 'page' => ['component' => 'x', 'props' => [], 'url' => '/contact', 'version' => 'v'], 'modules' => ['x' => '/system/prerender/modules/core/x.mjs'], 'messages' => []]);
        $refused = $this->api('POST', "/system/prerender/{$contact}", ['html' => '<p onclick="x()">x</p>']);
        $this->assertSame(422, $refused->statusCode(), $refused->content());
        $this->assertStringContainsString('onclick', $refused->content(), 'the answer says what was refused');
        $this->assertSame([], $purged->tags);
        $this->assertSame([$key], array_column($this->json($this->api('GET', '/system/prerender?limit=10'))['data']['pages'], 'key'));

        $saved = $this->api('POST', "/system/prerender/{$key}", ['html' => '<main>About</main>', 'title' => 'About']);
        $this->assertSame(200, $saved->statusCode(), $saved->content());
        $this->assertSame('/about', $this->json($saved)['data']['url']);
        $this->assertSame([PageRenderer::tag($key)], $purged->tags, 'the cached page is dropped, so the next visitor gets the HTML');
        $this->assertSame(['body' => '<main>About</main>', 'title' => 'About'], $store->find($key));

        $this->assertSame(422, $this->api('POST', "/system/prerender/{$key}", ['html' => '<p>again</p>'])->statusCode(), 'only waiting pages take HTML');
        $this->assertSame(404, $this->api('POST', '/system/prerender/nope', ['html' => '<p>x</p>'])->statusCode());
    }

    public function testTurnedOffItSavesNothing(): void
    {
        $this->createUser();
        $this->signIn('ana');

        $this->assertSame(['enabled' => false, 'saved' => 0, 'waiting' => 0, 'pages' => []], $this->json($this->api('GET', '/system/prerender'))['data']);
        $this->assertSame(404, $this->api('POST', '/system/prerender/' . str_repeat('a', 64), ['html' => '<p>x</p>'])->statusCode());
    }

    public function testItIsOnUnlessTurnedOffTheNodeRendererIsSetOrTheSiteIsADemo(): void
    {
        $this->assertInstanceOf(PrerenderStore::class, FrontendModule::prerender($this->cms, null));
        $this->assertNull(FrontendModule::prerender($this->cms, 'http://127.0.0.1:13714'), 'the Node renderer draws every page itself');
        $this->cms->config->set('app.prerender', false);
        $this->assertNull(FrontendModule::prerender($this->cms, null));

        // a demo's pages change every hour (the reset countdown), and everyone there is an administrator
        $this->cms->config->set('app.prerender', true);
        $this->cms->config->set('app.demo', true);
        $this->assertNull(FrontendModule::prerender($this->cms, null));
    }

    private function signIn(string $login): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private function bindStore(): PrerenderStore
    {
        $store = new PrerenderStore($this->tempDir() . '/prerender');
        $this->cms->container->instance(PrerenderStore::class, $store);

        return $store;
    }

    private function bindPageCache(): object
    {
        $cache = new class implements PageCache {
            /** @var list<string> */
            public array $tags = [];
            public function get(string $key): ?array
            {
                return null;
            }
            public function put(string $key, array $page, array $tags, int $ttl): void {}
            public function purge(string ...$tags): void
            {
                $this->tags = [...$this->tags, ...$tags];
            }
            public function flush(): void {}
        };
        $this->cms->container->instance(PageCache::class, $cache);

        return $cache;
    }
}
