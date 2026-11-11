<?php

declare(strict_types=1);

namespace Kestello\Cms\Frontend;

use Closure;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\I18n\BrowserCatalogue;
use Kestello\Cms\View\PageRegistry;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\PageResponse;
use Kestello\Cms\View\Prerender\PrerenderController;
use Kestello\Cms\View\Prerender\PrerenderStore;
use Kestello\Cms\View\SsrGateway;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Session\Session;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/**
 * Core module: Vue page rendering, shared page props and Content-Security-Policy.
 *
 * Shared props every page receives (extend with $cms->pages->share() or the
 * "page.shared" filter; never share secrets):
 *   site { name, url }, auth { user }, csrfToken, flash { success, error },
 *   errors { field: [messages] }, old { field: value }, recoveryMode
 */
final class FrontendModule extends Provider
{
    /** Default favicon and theme colour (Kestello); themes replace them with the "page.icons" filter. */
    public const string BRAND_ICONS = '<link rel="icon" type="image/svg+xml" href="/assets/core/brand/favicon.svg">' . "\n"
        . '<link rel="icon" href="/favicon.ico" sizes="any">' . "\n"
        . '<link rel="apple-touch-icon" href="/assets/core/brand/apple-touch-icon.png">' . "\n"
        . '<meta name="theme-color" content="#111827">' . "\n";

    public function boot(Cms $cms): void
    {
        $csp = new Csp($cms->filters);
        $ssrUrl = $cms->config->get('app.ssr.url');

        $renderer = new PageRenderer(
            registry: new PageRegistry($cms->paths, $cms->extensions(), $cms->version(), static function () use ($cms): array {
                try {
                    return $cms->container->has(\Kestello\Cms\Design\ThemeStyles::class) ? $cms->container->make(\Kestello\Cms\Design\ThemeStyles::class)->stylesheetUrls() : [];
                } catch (\Throwable $e) {
                    error_log('[kestello] global styles skipped: ' . $e->getMessage());     // e.g. not migrated yet
                    return [];
                }
            }),
            csp: $csp,
            paths: $cms->paths,
            shared: static fn(): array => self::shared($cms),
            head: static fn(array $data = []): string => (string) $cms->filters->apply('page.icons', self::BRAND_ICONS) . (string) $cms->filters->apply('page.head', '', $data),
            body: static fn(array $data = []): array => ['open' => (string) $cms->filters->apply('page.body_open', '', $data), 'close' => (string) $cms->filters->apply('page.footer', '', $data)],
            debug: (bool) $cms->config->get('app.debug', false),
            ssr: is_string($ssrUrl) && $ssrUrl !== '' ? new SsrGateway($ssrUrl, (float) $cms->config->get('app.ssr.timeout', 1.5)) : null,
            prerender: self::prerender($cms, $ssrUrl),
        );

        $cms->container->instance(Csp::class, $csp);
        $cms->container->instance(PageRenderer::class, $renderer);

        // where administrators' browsers draw waiting pages to HTML (saved page HTML)
        $prerender = static fn(): PrerenderController => new PrerenderController($cms, $renderer->registry());
        $cms->routes->get(PrerenderController::ROUTE, static fn(Request $r): Response => $prerender()->frame($r))->name('prerender.frame');
        $cms->routes->get(PrerenderController::modulesRoute(), static fn(Request $r, string $path): Response => $prerender()->module($r, $path))
            ->where(['path' => '.+'])
            ->name('prerender.modules');
        $middleware = new FrontendMiddleware($csp, $renderer);
        $cms->routes->pushGlobalMiddleware($middleware);

        // Error pages (404, 403, 500 …) render as Vue pages too.
        $cms->container->instance('kestello.error_page', self::errorPages($renderer, $middleware, static fn(Request $request) => \Kestello\Cms\Admin\AdminPageController::notFound($cms, $request)));
    }

    /**
     * Saved page HTML (app.prerender), for sites without the Node renderer:
     * off with one, and on a public demo, where everyone is an administrator.
     */
    public static function prerender(Cms $cms, mixed $ssrUrl): ?PrerenderStore
    {
        if ((is_string($ssrUrl) && $ssrUrl !== '') || $cms->config->get('app.prerender', true) === false || \Kestello\Cms\Demo\Demo::on($cms->config)) {
            return null;
        }
        $store = new PrerenderStore($cms->paths->storage('framework/prerender'));
        $cms->container->instance(PrerenderStore::class, $store);

        return $store;
    }

    /**
     * @param (Closure(Request): ?PageResponse)|null $adminNotFound the admin's own page for an address under /admin that leads nowhere
     * @return Closure(Request, Response): Response
     */
    public static function errorPages(PageRenderer $renderer, FrontendMiddleware $middleware, ?Closure $adminNotFound = null): Closure
    {
        return static fn(Request $request, Response $response): Response => $middleware->withCsp($renderer->render(
            $request,
            self::errorPage($request, $response->statusCode(), $adminNotFound),
            $response,
        ));
    }

    /**
     * The page for an error: the admin's "not found" under /admin (when $adminNotFound gives one),
     * else the theme's error page.
     *
     * @param (Closure(Request): ?PageResponse)|null $adminNotFound
     */
    public static function errorPage(Request $request, int $status, ?Closure $adminNotFound = null): PageResponse
    {
        $inAdmin = $request->path === '/admin' || str_starts_with($request->path, '/admin/');
        if ($status === 404 && $inAdmin && $adminNotFound !== null && ($page = $adminNotFound($request)) !== null) {
            return $page;
        }

        return new PageResponse('@core/errors/Error', ErrorPage::props($status), $status);
    }

    /**
     * What every site page gets. Public data only: all of it reaches the browser.
     *
     * @internal
     * @return array<string, mixed>
     */
    public static function shared(Cms $cms): array
    {
        $settings = Settings::getInstance();
        $user = Auth::getInstance()?->user();
        $session = $cms->container->has(Session::class) ? $cms->container->make(Session::class) : null;
        $active = $session !== null && session_status() === PHP_SESSION_ACTIVE;

        $errors = $active ? $session->getFlash('_errors') : null;
        $old = $active ? $session->getFlash('_old_input') : null;

        $shared = [
            'site' => [
                'name' => (string) ($settings->get('site_name') ?? $cms->config->get('app.name', 'Kestello')),
                'url' => (string) ($settings->get('site_url') ?? $cms->config->get('app.url', '')),
                'titles' => \Kestello\Cms\Site\Seo::titles(),
            ],
            // UI hints only: the server enforces every permission.
            'auth' => ['user' => $user === null ? null : [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
                'role' => (string) $user['role'],
                // whether to offer a way to the admin (a theme can't tell from the role's name)
                'admin' => in_array((string) $user['role'], $cms->auth->roles->rolesWith('admin.access'), true),
            ]],
            'csrfToken' => $active ? $session->token() : '',
            'flash' => [
                'success' => $active ? $session->getFlash('success') : null,
                'error' => $active ? $session->getFlash('error') : null,
            ],
            'errors' => is_array($errors) && $errors !== [] ? $errors : (object) [],
            'old' => is_array($old) && $old !== [] ? self::withoutSecrets($old) : (object) [],
            // the plugins' and theme's text in the page's language, for __() in their components; prefix starts
            // the theme's own links in that language ("/es" on a Spanish page, '' in the main language)
            'i18n' => ['locale' => Translator::locale(), 'translations' => BrowserCatalogue::url(Translator::locale(), site: true),
                'prefix' => \Kestello\Cms\I18n\ContentLanguages::prefix(\Kestello\Cms\I18n\ContentLanguages::current())],
        ];

        $shared = array_replace($shared, $cms->pages->resolve());
        $filtered = $cms->filters->apply('page.shared', $shared);

        return is_array($filtered) ? $filtered : $shared;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function withoutSecrets(array $input): array
    {
        return array_filter($input, static fn($v, $k): bool => preg_match('/pass|secret|token|key/i', (string) $k) !== 1, ARRAY_FILTER_USE_BOTH);
    }
}
