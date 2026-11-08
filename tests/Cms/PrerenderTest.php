<?php

declare(strict_types=1);

namespace Tests\Cms;

use Kestello\Cms\Extension\AssetController;
use Kestello\Cms\Frontend\Csp;
use Kestello\Cms\View\PageRegistry;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\PageResponse;
use Kestello\Cms\View\Prerender\PrerenderStore;
use Kestello\Cms\View\SsrGateway;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Testing\TestCase;

/**
 * Saved page HTML (View\Prerender) and the components the Node renderer is sent.
 */
final class PrerenderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir() . '/prerender';
    }

    public function testTheNodeRendererIsSentEveryBlock(): void
    {
        $payload = SsrGateway::payload(['component' => '@core/content/Entry', 'props' => [], 'url' => '/', 'version' => 'x'], $this->registry());

        $this->assertNotNull($payload);
        // without them Node drew a page with no content: blocks are drawn by name from the registry
        foreach (['@core/content/Entry', 'layouts/Site', '@core/blocks/Buttons', '@core/blocks/Columns'] as $name) {
            $this->assertArrayHasKey($name, $payload['modules'], "{$name} is sent");
            $this->assertFileExists($payload['modules'][$name]);
        }
    }

    public function testTheSiteSsrModulesLeaveTheAdminOut(): void
    {
        $modules = $this->registry()->siteSsr();

        $this->assertArrayHasKey('@core/blocks/Buttons', $modules);
        foreach (array_keys($modules) as $name) {
            $this->assertSame(0, preg_match('#^(layouts/Admin$|@core/(admin|fields|installer)/)#', $name), "{$name} is not part of the site");
        }
        $buttons = $modules['@core/blocks/Buttons'];
        $this->assertStringStartsWith(PageRegistry::SSR_MODULES . '/core/~0.1.0/build/ssr/', $buttons['url']);
        $this->assertSame(realpath($buttons['path']), AssetController::resolveSsr(dirname(__DIR__, 2) . '/core/resources/assets', substr($buttons['url'], strlen(PageRegistry::SSR_MODULES . '/core/'))));
    }

    public function testSsrModulesResolveOnlyInsideTheSsrBuild(): void
    {
        $root = dirname(__DIR__, 2) . '/core/resources/assets';

        $this->assertNull(AssetController::resolveSsr($root, '~1/build/app/index.js'), 'client builds are public assets, not these');
        $this->assertNull(AssetController::resolveSsr($root, 'build/ssr/../../vendor/vendor.json'));
        $this->assertNull(AssetController::resolveSsr($root, 'build/ssr/.vite/manifest.json'));
        $this->assertNull(AssetController::resolveSsr($root, 'build/ssr/nope.mjs'));
    }

    public function testAWaitingPageTakesItsHtmlOnce(): void
    {
        $store = new PrerenderStore($this->dir);
        $key = str_repeat('a', 64);
        $store->want($key, $this->item('/about'));
        $store->want($key, $this->item('/about'));

        $this->assertSame(['saved' => 0, 'waiting' => 1], $store->counts());
        $this->assertSame([$key], array_column($store->waiting(5), 'key'));
        $this->assertSame('/about', $store->save($key, '<main><h1>About</h1></main>', 'About · Acme'));
        $this->assertSame(['body' => '<main><h1>About</h1></main>', 'title' => 'About · Acme'], $store->find($key));
        $this->assertSame(['saved' => 1, 'waiting' => 0], $store->counts());

        // saved already, or never asked for: HTML for data the server didn't produce is refused
        $this->assertThrows(\InvalidArgumentException::class, fn() => $store->save($key, '<p>x</p>', ''));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $store->save(str_repeat('b', 64), '<p>x</p>', ''));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $store->save('../../x', '<p>x</p>', ''));
        $store->want($key, $this->item('/about'));
        $this->assertSame(0, $store->counts()['waiting'], 'a saved page is not asked for again');
    }

    public function testHtmlThatDidNotComeFromVueIsRefused(): void
    {
        $this->assertNull(PrerenderStore::refuse('<p class="x">Write onclick="go()" in your code</p><a href="/js">js</a>'));
        foreach ([
            '' => 'empty',
            '<p>a</p><script>alert(1)</script>' => 'a script',
            '<p>a</p>< SCRIPT src=x>' => 'a script, spaced',
            '<img src=x onerror=alert(1)>' => 'a handler',
            '<div/onmouseover="x">' => 'a handler after a slash',
            '<a href="javascript:alert(1)">x</a>' => 'a javascript: address',
            '<a href=" JavaScript:x">x</a>' => 'a javascript: address, spaced',
            str_repeat('a', PrerenderStore::MAX_BYTES + 1) => 'too large',
        ] as $html => $why) {
            $this->assertNotNull(PrerenderStore::refuse((string) $html), "refused: {$why}");
        }
    }

    public function testTheQueueKeepsTheNewestPages(): void
    {
        $store = new PrerenderStore($this->dir);
        for ($i = 0; $i <= PrerenderStore::MAX_WAITING; $i++) {
            $store->want(hash('sha256', (string) $i), $this->item("/p{$i}"));
            touch($this->dir . '/queue/' . hash('sha256', (string) $i) . '.json', 1_000_000 + $i);
        }
        $store->want(hash('sha256', 'last'), $this->item('/last'));

        $this->assertSame(PrerenderStore::MAX_WAITING, $store->counts()['waiting']);
        $this->assertSame('/last', $store->waiting(1)[0]['url']);
        $store->forget(hash('sha256', 'last'));
        $this->assertSame(PrerenderStore::MAX_WAITING - 1, $store->counts()['waiting']);
    }

    public function testAGuestPageWaitsForItsHtmlAndIsSentWithIt(): void
    {
        $store = new PrerenderStore($this->dir);
        $html = $this->page($store, '/about');

        $this->assertStringContainsString('<div id="app"></div>', $html, 'drawn in the browser until the HTML is saved');
        $waiting = $store->waiting(5);
        $this->assertCount(1, $waiting);
        $this->assertSame('/about', $waiting[0]['url']);
        $this->assertSame('@core/content/Entry', $waiting[0]['page']['component']);
        $this->assertArrayNotHasKey('preload', $waiting[0]['page'], 'the key is the data the browser gets');
        $this->assertStringStartsWith(PageRegistry::SSR_MODULES . '/', $waiting[0]['modules']['@core/blocks/Buttons']);

        $store->save($waiting[0]['key'], '<main><h1>About us</h1></main>', 'About us · Acme');
        $html = $this->page($store, '/about');

        $this->assertStringContainsString('<div id="app" data-ssr><main><h1>About us</h1></main></div>', $html);
        $this->assertStringContainsString('<title>About us · Acme</title>', $html);
        $this->assertSame(0, $store->counts()['waiting']);
    }

    public function testOnlyPagesEveryVisitorGetsWait(): void
    {
        $store = new PrerenderStore($this->dir);
        $this->page($store, '/about', ['auth' => ['user' => ['id' => 1]]]);
        $this->page($store, '/about', ['csrfToken' => 'abc']);
        $this->page($store, '/about', ['errors' => ['email' => ['Required.']]]);
        $this->page($store, '/about', ['old' => ['email' => 'a@b.test']]);
        $this->page($store, '/about', ['flash' => ['success' => 'Sent.', 'error' => null]]);
        $this->page($store, '/about', ['admin' => ['capabilities' => []]]);
        $this->page($store, '/search?q=rye');
        $this->page($store, '/about', status: 404);
        $this->assertSame(0, $store->counts()['waiting']);

        $this->page($store, '/blog?page=2');
        $this->page($store, '/about', ['flash' => ['success' => null, 'error' => null]]);
        $this->assertSame(2, $store->counts()['waiting']);
    }

    public function testThePageCacheTagIsValid(): void
    {
        $this->assertSame(1, preg_match('/^[a-z0-9][a-z0-9:._-]{0,100}$/', PageRenderer::tag(str_repeat('f', 64))));
    }

    /** @return array{url: string, page: array<string, mixed>, modules: array<string, string>, messages: array<string, mixed>} */
    private function item(string $url): array
    {
        return ['url' => $url, 'page' => ['component' => 'x', 'props' => [], 'url' => $url, 'version' => 'v'], 'modules' => [], 'messages' => []];
    }

    /** @param array<string, mixed> $shared */
    private function page(PrerenderStore $store, string $url, array $shared = [], int $status = 200): string
    {
        $paths = new Paths($this->tempDir(), dirname(__DIR__, 2) . '/core');
        $renderer = new PageRenderer($this->registry(), new Csp(), $paths, fn() => $shared + ['site' => ['name' => 'Acme'], 'auth' => ['user' => null], 'csrfToken' => '', 'errors' => (object) [], 'old' => (object) [], 'flash' => ['success' => null, 'error' => null]], fn() => '', prerender: $store);
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $request = new Request('GET', $url, $path, [], $query, [], [], [], []);

        return $renderer->render($request, new PageResponse('@core/content/Entry', ['title' => 'About'], preload: ['@core/blocks/Buttons']), new Response('', $status))->content();
    }

    private function registry(): PageRegistry
    {
        return new PageRegistry(new Paths($this->tempDir(), dirname(__DIR__, 2) . '/core'), null, '0.1.0');
    }
}
