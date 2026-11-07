<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Core\Request;

/**
 * Discovery for the public site: head tags on every page, feeds,
 * sitemap.xml and robots.txt. Per-type and per-term feeds are added by
 * ContentModule::finalize (they must come before the types' own routes).
 */
final class SiteModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $cms->filters->add('page.head', static fn(string $html, array $data = []): string => Seo::head($cms, $data) . $html, 100);

        // Settings → Code: administrators only ("*"); nobody else is granted it by default.
        $cms->auth->roles->capability('snippets.manage', 'Add code to every page of the site', sensitive: true);
        $nonce = static fn(): string => $cms->container->make(\Kestello\Cms\Frontend\Csp::class)->nonce();
        $cms->filters->add('page.head', static fn(string $html, array $data = []): string => $html . Snippets::render('head', $data, $nonce()), -100);
        $cms->filters->add('page.body_open', static fn(string $html, array $data = []): string => Snippets::render('body', $data, $nonce()) . $html, -100);
        $cms->filters->add('page.footer', static fn(string $html, array $data = []): string => $html . Snippets::render('footer', $data, $nonce()), -100);

        $routes = $cms->routes;
        $routes->get('/robots.txt', static fn() => Discovery::robots($cms))->name('site.robots');
        $routes->get('/sitemap.xml', static fn() => Discovery::sitemapIndex($cms))->name('site.sitemap');
        $routes->get('/sitemaps/{file}', static fn(Request $r, string $file) => Discovery::sitemap($cms, $file))
            ->where(['file' => '[a-z0-9_]+-[0-9]+\.xml'])->name('site.sitemap.file');
        $routes->get('/feed', static fn(Request $r) => Discovery::feed($cms, $r, 'rss'))->name('site.feed');
        $routes->get('/feed/atom', static fn(Request $r) => Discovery::feed($cms, $r, 'atom'))->name('site.feed.atom');
    }

    /** @internal ContentModule::finalize: /blog/feed, /category/news/feed (and /atom), before the types' routes. */
    public static function contentRoutes(Cms $cms): void
    {
        $routes = $cms->routes;
        foreach ($cms->content->taxonomies() as $tax) {
            if ($tax->getPath() !== null) {
                $name = $tax->name;
                foreach (['rss' => '', 'atom' => '/atom'] as $format => $suffix) {
                    $routes->get($tax->getPath() . '/{slug}/feed' . $suffix, static fn(Request $r, string $slug) => Discovery::feed($cms, $r, $format, taxName: $name, slug: $slug))
                        ->where(['slug' => '[a-z0-9][a-z0-9-]*']);
                }
            }
        }
        foreach ($cms->content->types() as $type) {
            if ($type->hasArchive()) {
                $name = $type->name;
                foreach (['rss' => '', 'atom' => '/atom'] as $format => $suffix) {
                    $routes->get($type->getPath() . '/feed' . $suffix, static fn(Request $r) => Discovery::feed($cms, $r, $format, typeName: $name));
                }
            }
        }
    }

    /** @internal ContentModule::finalize: the "Search and sharing" fields on every public type. */
    public static function contentFields(Cms $cms): void
    {
        foreach ($cms->content->types() as $type) {
            /** @var ContentType $type */
            if ($type->getPath() !== null && !in_array('seo', array_map(static fn($f) => $f->name, $type->getFields()), true)) {
                $type->field(Seo::field());
            }
        }
    }
}
