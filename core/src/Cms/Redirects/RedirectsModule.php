<?php

declare(strict_types=1);

namespace Kestello\Cms\Redirects;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Database\Orm\Events\ModelEvent;
use WeakMap;

/**
 * Core module: redirects, automatic redirects when published entries move,
 * the "not found" log and the Appearance → Redirects screen.
 */
final class RedirectsModule extends Provider
{
    /** @var WeakMap<Entry, string>|null old addresses of entries being saved */
    private static ?WeakMap $moving = null;

    public function register(Container $container): void
    {
        $container->singleton(Redirects::class, static fn() => new Redirects());
    }

    public function boot(Cms $cms): void
    {
        $cms->auth->roles->capability('redirects.manage', 'Manage redirects and see addresses that were not found', grantTo: ['editor']);
        $cms->admin->page('redirects', '@core/admin/redirects/Index', 'Redirects', 'redirects.manage', ['label' => 'Redirects', 'icon' => 'signpost', 'group' => 'appearance', 'order' => 30]);
        $redirects = $cms->container->make(Redirects::class);
        $cms->routes->pushGlobalMiddleware(new RedirectMiddleware($redirects));
        $cms->schedule->call(static fn() => $redirects->pruneLog(), 'redirects.prune-log')->daily();

        // A published entry's address changed: send the old one to the new one (children too, by prefix).
        self::$moving = new WeakMap();
        $cms->models->on(Entry::class, 'updating', static function (ModelEvent $e): void {
            $entry = $e->model;
            if (!$entry instanceof Entry || !$entry->isDirty('slug', 'parent_id') || $entry->getOriginal('status') !== 'published') {
                return;
            }
            $old = clone $entry;
            $old->slug = (string) $entry->getOriginal('slug');
            $old->parent_id = $entry->getOriginal('parent_id') === null ? null : (int) $entry->getOriginal('parent_id');
            $path = $old->path();
            if ($path !== null) {
                self::$moving[$entry] = $path;
            }
        });
        $cms->models->on(Entry::class, 'saved', static function (ModelEvent $e) use ($redirects): void {
            $entry = $e->model;
            if (!$entry instanceof Entry || !isset(self::$moving[$entry])) {
                return;
            }
            $from = self::$moving[$entry];
            unset(self::$moving[$entry]);
            $to = $entry->path();
            if ($to !== null && $entry->status === 'published') {
                $redirects->recordMove($from, $to, (int) $entry->getKey(), (bool) $entry->contentType()?->isHierarchical());
            }
        });
    }
}
