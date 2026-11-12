<?php

declare(strict_types=1);

namespace Kestello\Cms\Cache;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Term;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Health\HealthResult;
use Kestello\Contracts\Cache\PageCache;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Database\Orm\Events\ModelEvent;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Settings\Settings;

/**
 * The page cache: binding, middleware and purges.
 *
 * Tags: entry:{id} (an entry's own page), list:{type} (lists that can show a
 * type: archives, terms, dates, authors, query blocks), terms (pages showing
 * term names), content (pages that didn't say what they show, feeds, the
 * sitemap) and all (every page). Saving an entry purges its page, its type's
 * lists and "content"; settings, menus, media, profiles and platform changes
 * flush everything. Deploys and theme or plugin changes need nothing: the
 * page registry's version is part of every key.
 */
final class PageCacheModule extends Provider
{
    /** Recorded changes that can show up on any page. */
    private const array FLUSH_ON = ['settings.updated', 'menu.', 'media.updated', 'media.deleted', 'platform.changed', 'profile.updated', 'profile.email_confirmed', 'user.', 'content_model.', 'theme.'];

    public function register(Container $container): void
    {
        $container->singleton(PageCache::class, static fn(Container $c): PageCache => new TaggedPageCache($c->make(Store::class)));
    }

    public function boot(Cms $cms): void
    {
        $cms->routes->pushGlobalMiddleware(new PageCacheMiddleware($cms));
        $cache = static fn(): PageCache => $cms->container->make(PageCache::class);
        $purge = static function (string ...$tags) use ($cache): void {
            try {
                $cache()->purge(...$tags);
            } catch (\Throwable $e) {
                Log::error('page cache purge failed: ' . $e->getMessage());
            }
        };

        foreach (['saved', 'deleted', 'restored'] as $event) {
            $cms->models->on(Entry::class, $event, static function (ModelEvent $e) use ($purge, $event): void {
                if ($e->model instanceof Entry) {
                    $purge('entry:' . $e->model->getKey(), 'list:' . $e->model->type, 'content');
                    if ($event !== 'saved' || $e->model->wasChanged('status', 'published_at')) {
                        $purge('terms');    // a term's page and menu link depend on whether a published entry uses it
                    }
                }
            });
            $cms->models->on(Term::class, $event, static function (ModelEvent $e) use ($purge, $cms): void {
                if ($e->model instanceof Term) {
                    $types = $cms->content->getTaxonomy((string) $e->model->taxonomy)?->getTypes() ?? [];
                    $purge('terms', 'content', ...array_map(static fn(string $t) => 'list:' . $t, $types));
                }
            });
        }
        $cms->audit->listen(static function (string $event) use ($purge): void {
            foreach (self::FLUSH_ON as $prefix) {
                if ($event === $prefix || (str_ends_with($prefix, '.') && str_starts_with($event, $prefix))) {
                    $purge('all');
                    return;
                }
            }
        });

        $cms->health->check('core.page-cache', 'Page cache', static function () use ($cms): HealthResult {
            $value = Settings::getInstance()->get('page_cache');
            $on = $value === null || $value === '' ? !(bool) $cms->config->get('app.debug', false) : filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $details = ['enabled' => $on, 'store' => (new \ReflectionClass($cms->container->make(PageCache::class)))->getShortName()];
            if (!$on) {
                return HealthResult::recommended('Pages are rendered for every visitor. Turn the page cache on under Settings → Performance.', $details, ['Open settings', '/admin/settings']);
            }

            return HealthResult::good('Pages are cached for visitors who aren\'t signed in, and refreshed when content changes.', $details);
        }, 'performance');
    }
}
