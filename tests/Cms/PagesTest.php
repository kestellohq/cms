<?php

declare(strict_types=1);

namespace Tests\Cms;

use Kestello\Cms\Frontend\Csp;
use Kestello\Cms\Frontend\FrontendMiddleware;
use Kestello\Cms\View\Page;
use Kestello\Cms\View\PageRegistry;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\PageResponse;
use Kestello\Cms\View\Pages;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Mail\MailTemplate;
use Kestello\Framework\Testing\TestCase;
use RuntimeException;

/**
 * Vue page rendering against the committed core build (run "npm run build" after changing core/resources/js).
 */
final class PagesTest extends TestCase
{
    private Csp $csp;

    protected function setUp(): void
    {
        $this->csp = new Csp();
    }

    public function testCoreBuildIsRegistered(): void
    {
        $registry = $this->registry();

        $this->assertNotNull($registry->runtime(), 'core runtime built');
        foreach (['@core/auth/Login', '@core/admin/Dashboard', '@core/errors/Error', '@core/installer/Database', 'layouts/Site'] as $name) {
            $this->assertTrue($registry->has($name), "{$name} registered");
        }
        $loginCss = implode(' ', $registry->get('@core/auth/Login')['css']);
        $this->assertTrue(str_contains($loginCss, '/css/admin-ui.css?h=') && str_contains($loginCss, '/css/phosphor.css?h='), 'sign-in wears the UI kit and its icons');
        $this->assertFalse(str_contains($loginCss, 'bootstrap'), 'no CSS framework besides the UI kit');
        $templateCss = $registry->get('@core/admin/appearance/TemplateEdit')['css'];
        $this->assertTrue((bool) array_filter($templateCss, static fn($u) => preg_match('#/build/assets/index-[^/]+\.css$#', $u) === 1), 'admin pages load the UI kit components\' own styles (the builder\'s layout lives there)');
        foreach (['@core/content/Entry', '@core/content/Search', '@core/blocks/Heading', '@core/errors/Error'] as $site) {
            $this->assertTrue(PageRegistry::isSitePage($site), "{$site} wears the theme's stylesheets, not the admin skin");
        }
        foreach (['@core/auth/Login', '@core/admin/Dashboard', '@core/fields/Text'] as $admin) {
            $this->assertFalse(PageRegistry::isSitePage($admin), $admin);
        }
        $this->assertSame(12, strlen($registry->version()));
    }

    public function testNavigationGetsPageJsonWithSharedProps(): void
    {
        $response = $this->renderer(['site' => ['name' => 'Acme'], 'title' => 'shared'])
            ->render($this->request(['X-Kestello' => 'true']), new PageResponse('@core/auth/Login', ['title' => 'Login']), new Response('', 422));

        $page = json_decode($response->content(), true);
        $this->assertSame(422, $response->statusCode());
        $this->assertSame('application/json', $response->headers()['Content-Type']);
        $this->assertSame('@core/auth/Login', $page['component']);
        $this->assertSame('Login', $page['props']['title'], 'page props win over shared');
        $this->assertSame('Acme', $page['props']['site']['name']);
        $this->assertSame('/login?x=1', $page['url']);
    }

    public function testFirstVisitGetsSafeHtmlShell(): void
    {
        $html = $this->renderer(['site' => ['name' => 'A&B']])
            ->render($this->request(), new PageResponse('@core/auth/Login', ['title' => '</title><script>x</script>', 'bio' => '</script><img src=x>']), new Response())
            ->content();

        $nonce = $this->csp->nonce();
        $this->assertContains('<title>&lt;/title&gt;&lt;script&gt;x&lt;/script&gt; - A&amp;B</title>', $html);
        $this->assertNotContains('</script><img', $html, 'props cannot break out of the JSON script');
        $this->assertContains(substr(json_encode('</script><img', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES), 1, -1), $html);
        $this->assertContains("<script type=\"importmap\" nonce=\"{$nonce}\">", $html);
        $this->assertContains("<script type=\"module\" nonce=\"{$nonce}\">import { boot } from '@kestello/app'", $html);
        $this->assertContains('"integrity":{"/assets/core/vendor/vue-', $html);
        $this->assertContains('<link rel="modulepreload" href="/assets/core/~0.1.0/build/app/index-', $html);
        $this->assertTrue((bool) preg_match('#href="/assets/core/~0\.1\.0/css/admin-ui\.css\?h=[0-9a-f]+" data-kestello-css#', $html));
        $this->assertNotContains(' data-ssr', $html);

        preg_match('#<script type="application/json" id="kestello-page">(.*?)</script>#s', $html, $m);
        $this->assertSame('</script><img src=x>', json_decode($m[1], true)['props']['bio']);
    }

    public function testUnknownComponentThrowsInDebugAndDegradesInProduction(): void
    {
        $this->assertThrows(RuntimeException::class, fn() => $this->renderer(debug: true)->render($this->request(), new PageResponse('Nope'), new Response()));

        $response = @$this->renderer()->render($this->request(), new PageResponse('Nope'), new Response());
        $this->assertContains('Page unavailable', $response->content());
    }

    public function testMiddlewareRendersPagesAndAddsCsp(): void
    {
        $middleware = new FrontendMiddleware($this->csp, $this->renderer());

        $html = $middleware->handle($this->request(), fn() => Page::render('@core/auth/Login'));
        $this->assertContains('<div id="app">', $html->content());
        $this->assertContains("'nonce-{$this->csp->nonce()}'", $html->headers()['Content-Security-Policy']);

        $json = $middleware->handle($this->request(['X-Kestello' => 'true']), fn() => Page::render('@core/auth/Login'));
        $this->assertFalse(isset($json->headers()['Content-Security-Policy']), 'no CSP on JSON');
    }

    public function testStaleClientIsToldToReload(): void
    {
        $middleware = new FrontendMiddleware($this->csp, $this->renderer());
        $stale = ['X-Kestello' => 'true', 'X-Kestello-Version' => 'old'];
        $reached = false;

        $response = $middleware->handle($this->request($stale), function () use (&$reached) {
            $reached = true;
            return new Response();
        });
        $this->assertSame(409, $response->statusCode());
        $this->assertSame('/login?x=1', $response->headers()['X-Kestello-Location']);
        $this->assertFalse($reached, 'controller never runs for stale clients');

        // Non-GET requests still run (the form submission must not be lost).
        $post = $middleware->handle($this->request($stale, 'POST'), fn() => Response::redirect('/'));
        $this->assertSame(302, $post->statusCode());
        $put = $middleware->handle($this->request($stale, 'PUT'), fn() => Response::redirect('/'));
        $this->assertSame(303, $put->statusCode(), 'PUT redirects become 303 so the browser follows with GET');
    }

    public function testSharedPropsResolveLazily(): void
    {
        $pages = new Pages();
        $calls = 0;
        $pages->share('count', function () use (&$calls) {
            return ++$calls;
        });
        $pages->share('plain', 'value');

        $this->assertSame(0, $calls);
        $this->assertSame(['count' => 1, 'plain' => 'value'], $pages->resolve());
    }

    public function testMailTemplatesEscapeByDefault(): void
    {
        $this->assertSame(
            'Hi &lt;b&gt;Ana&lt;/b&gt;, <i>ok</i> !',
            MailTemplate::render('Hi {{ user.name }}, {!! html !!} {{ missing }}!', ['user' => ['name' => '<b>Ana</b>'], 'html' => '<i>ok</i>']),
        );
    }

    public function testCspIsExtensibleButCannotBeSmuggled(): void
    {
        $filters = new Filters();
        $filters->add('security.csp', fn(array $d) => $d + [
            'connect-src' => "'self' https://api.example.com",
            'img-src' => "'self'; script-src *",
            'bad name' => 'x',
        ]);

        $csp = new Csp($filters);
        $header = $csp->header();

        $this->assertContains("script-src 'nonce-{$csp->nonce()}' 'strict-dynamic'", $header);
        $this->assertContains("connect-src 'self' https://api.example.com", $header);
        $this->assertNotContains('img-src', $header);
        $this->assertNotContains('bad name', $header);
        $this->assertTrue($csp->nonce() !== (new Csp())->nonce(), 'fresh nonce per request');
    }

    public function testVendoredVueMatchesRecordedIntegrity(): void
    {
        $dir = dirname(__DIR__, 2) . '/core/resources/assets/vendor';
        $vue = json_decode((string) file_get_contents("$dir/vendor.json"), true)['vue'];

        $this->assertSame($vue['integrity'], 'sha384-' . base64_encode(hash_file('sha384', "$dir/{$vue['file']}", true)));
        $this->assertContains("vue v{$vue['version']}", (string) file_get_contents("$dir/{$vue['file']}", length: 64));
    }

    private function registry(): PageRegistry
    {
        return new PageRegistry(new Paths($this->tempDir(), dirname(__DIR__, 2) . '/core'), null, '0.1.0');
    }

    /**
     * @param array<string, mixed> $shared
     */
    private function renderer(array $shared = [], bool $debug = false): PageRenderer
    {
        $registry = $this->registry();

        return new PageRenderer($registry, $this->csp, new Paths($this->tempDir(), dirname(__DIR__, 2) . '/core'), fn() => $shared, fn() => '', $debug);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers = [], string $method = 'GET'): Request
    {
        return new Request($method, '/login?x=1', '/login', $headers, ['x' => '1'], [], [], [], []);
    }
}
