<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/** Core module: global styles, theme documents, the Styles screen. */
final class DesignModule extends Provider
{
    public function register(Container $container): void
    {
        $container->singleton(ThemeDocuments::class, static fn() => new ThemeDocuments());
        $container->singleton(ThemeFonts::class, static fn() => new ThemeFonts(Cms::instance()));
        $container->singleton(ThemeStyles::class, static fn(Container $c) => new ThemeStyles(Cms::instance(), $c->make(ThemeDocuments::class), $c->make(ThemeFonts::class)));
        $container->singleton(ThemeTemplates::class, static fn(Container $c) => new ThemeTemplates(Cms::instance(), $c->make(ThemeDocuments::class)));
        $container->singleton(ThemePackages::class, static fn(Container $c) => new ThemePackages(Cms::instance(), $c->make(ThemeDocuments::class), $c->make(ThemeTemplates::class), $c->make(ThemeStyles::class), $c->make(ThemeFonts::class)));
        $container->singleton(DesignCanvas::class, static fn(Container $c) => new DesignCanvas(Cms::instance(), $c->make(ThemeTemplates::class)));
    }

    public function boot(Cms $cms): void
    {
        $roles = $cms->auth->roles;
        $roles->capability('theme.design', 'Change the site\'s styles, templates and patterns');
        $roles->capability('theme.custom_css', 'Write custom CSS');
        $roles->capability('theme.manage', 'Create, switch, export and import themes', sensitive: true);

        // what editors pick from when they style a block: the theme's presets
        $cms->blocks->design->usePresets(static fn(): array => $cms->container->make(ThemeStyles::class)->presets());

        // the site's main font starts downloading with the HTML, not after the CSS (site pages only)
        $cms->filters->add('page.head', static function (string $html, array $data = []) use ($cms): string {
            if (isset($data['props']['admin'])) {
                return $html;
            }
            try {
                foreach ($cms->container->make(ThemeStyles::class)->preloadUrls() as $url) {
                    $html .= '<link rel="preload" href="' . htmlspecialchars($url, ENT_QUOTES) . '" as="font" type="font/woff2" crossorigin>' . "\n";
                }
            } catch (\Throwable $e) {
                error_log('[kestello] font preload skipped: ' . $e->getMessage());     // e.g. not migrated yet
            }

            return $html;
        }, 50);

        $cms->routes->get(ThemeStyles::ROUTE, static function (Request $request) use ($cms): Response {
            $css = $cms->container->make(ThemeStyles::class)->publishedCss();
            $hash = substr(hash('sha256', $css), 0, 12);
            $versioned = ($request->query['v'] ?? null) === $hash;

            return new Response($css, 200, [
                'Content-Type' => 'text/css; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                // a link with the current hash never changes; anything else must ask again
                'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'no-cache',
                'ETag' => '"' . $hash . '"',
            ]);
        })->name('theme.styles');

        // fonts downloaded to this site: file names never change, so they cache forever
        $cms->routes->get(ThemeFonts::ROUTE . '/{slug}/{file}', static function (Request $request, string $slug, string $file) use ($cms): Response {
            $path = $cms->container->make(ThemeFonts::class)->file($slug, $file);
            $handle = $path === null ? false : fopen($path, 'rb');
            if ($handle === false) {
                return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            return Response::stream($handle, 200, [
                'Content-Type' => 'font/woff2',
                'Content-Length' => (string) filesize($path),
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
                'Access-Control-Allow-Origin' => '*',
            ]);
        })->where(['slug' => 'gf-[a-z0-9-]+', 'file' => '[a-z0-9-]+\\.woff2'])->name('theme.fonts');

        $cms->routes->get('/system/themes/{slug}/screenshot', static function (Request $request, string $slug) use ($cms): Response {
            $theme = $cms->extensions()->themes()[$slug] ?? null;
            $path = $theme === null ? null : $cms->container->make(ThemePackages::class)->screenshot($theme);
            $handle = $path === null ? false : fopen($path, 'rb');
            if ($handle === false) {
                return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

            return Response::stream($handle, 200, [
                'Content-Type' => ['png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg',
                'Content-Length' => (string) filesize((string) $path),
                'Cache-Control' => 'public, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        })->where(['slug' => '[a-z0-9-]+'])->name('theme.screenshot');

        $cms->routes->get(DesignCanvas::ROUTE, static fn(Request $request): Response => $cms->container->make(DesignCanvas::class)->handle($request))->name('design.canvas');

        DesignBlocks::register($cms);
        $cms->admin->page('appearance/templates', '@core/admin/appearance/Templates', 'Templates', 'theme.design', ['label' => 'Templates', 'icon' => 'squares-four', 'group' => 'appearance', 'order' => 6]);
        $cms->admin->page('appearance/templates/{kind}/{key}', '@core/admin/appearance/TemplateEdit', 'Template', 'theme.design');
        $cms->admin->page('appearance/themes', '@core/admin/appearance/Themes', 'Themes', 'theme.manage', ['label' => 'Themes', 'icon' => 'columns', 'group' => 'appearance', 'order' => 4]);
        $cms->admin->page('appearance/styles', '@core/admin/appearance/Styles', 'Styles', 'theme.design', ['label' => 'Styles', 'icon' => 'palette', 'group' => 'appearance', 'order' => 5]);
    }
}
