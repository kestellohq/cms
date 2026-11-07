<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Request;

/**
 * Core module: the page and post types, editorial roles, scheduled
 * publishing, the Content admin screens and public routes.
 */
final class ContentModule extends Provider
{
    public function register(Container $container): void
    {
        $container->singleton(Entries::class, static fn(Container $c) => new Entries(Cms::instance()->content, Cms::instance()->events, null, $c->has(Store::class) ? $c->make(Store::class) : null));
        $container->singleton(RevisionDiff::class, static fn() => new RevisionDiff(Cms::instance()->content));
        $container->singleton(Reviews::class, static function (Container $c): Reviews {
            $cms = Cms::instance();

            return new Reviews($cms->content, $c->make(Entries::class), $cms->auth->roles, $cms->events, $cms->notifications, $cms->audit, (string) $cms->config->get('app.url', ''));
        });
        $container->singleton(Terms::class, static fn() => new Terms());
        $container->singleton(EntryRelations::class, static fn() => new EntryRelations(Cms::instance()->content));
        $container->singleton(Models\ContentModels::class, static fn() => new Models\ContentModels(Cms::instance()));
        $container->singleton(\Kestello\Cms\Menus\Menus::class, static fn() => new \Kestello\Cms\Menus\Menus(Cms::instance()->content, Cms::instance()->menus));
    }

    public function boot(Cms $cms): void
    {
        $roles = $cms->auth->roles;
        $roles->role('editor', 'Editor', ['read', 'profile.manage', 'admin.access', 'content.access', 'menus.manage']);
        $roles->role('author', 'Author', ['read', 'profile.manage', 'admin.access', 'content.access']);
        $roles->role('contributor', 'Contributor', ['read', 'profile.manage', 'admin.access', 'content.access']);

        $cms->notifications->type(ReviewNotification::class, 'Reviews', 'When an entry is submitted to you for review, a reviewer decides on yours, or one is assigned to you');
        $cms->notifications->type(CommentNotification::class, 'Editorial comments', 'When someone comments on an entry you wrote, are assigned to or commented on');
        $cms->container->make(Reviews::class)->listen($cms->events);

        $cms->content->type('page')
            ->label('Page', 'Pages')->icon('file')->hierarchical()->path('')->menuOrder(10)
            ->description('Standalone pages such as About or Contact, nested under each other.')
            ->fields([Field::blocks('body')->label('Content')]);
        $cms->content->type('post')
            ->label('Post', 'Posts')->icon('note-pencil')->path('/blog')->archive()->excerpt()->menuOrder(20)
            ->description('Dated articles listed on the blog.')
            ->fields([Field::blocks('body')->label('Content'), Field::image('image')->label('Featured image')->sidebar()]);

        $cms->content->taxonomy('category')
            ->label('Category', 'Categories')->hierarchical()->for('post')->path('/category')->menuOrder(10)
            ->description('Broad sections of the blog. Categories can nest.');
        $cms->content->taxonomy('tag')
            ->label('Tag', 'Tags')->for('post')->path('/tag')->menuOrder(20)
            ->description('Free-form keywords. Authors can add new ones while writing.');

        $cms->admin->page('content/{type}', '@core/admin/content/Index', 'Content', 'content.access');
        $cms->admin->page('content/{type}/{id}', '@core/admin/content/Edit', 'Edit', 'content.access', where: ['id' => AdminRegistry::ID . '|new']);
        $cms->admin->page('taxonomies/{taxonomy}', '@core/admin/content/Terms', 'Terms', 'content.access');

        $cms->auth->roles->capability('content_models.manage', 'Build content types, fields and taxonomies');
        $cms->admin->page('content-types', '@core/admin/content-types/Index', 'Content types', 'content_models.manage', ['label' => 'Content types', 'icon' => 'tree-structure', 'group' => 'system', 'order' => 50]);
        $cms->admin->page('content-types/{id}', '@core/admin/content-types/Edit', 'Content type', 'content_models.manage', where: ['id' => AdminRegistry::ID]);
        $cms->admin->page('menus', '@core/admin/menus/Index', 'Menus', 'menus.manage', ['label' => 'Menus', 'icon' => 'tree-view', 'group' => 'appearance', 'order' => 10]);

        // Every page gets the menus of the active theme's locations (usePage().props.menus.primary).
        $cms->pages->share('menus', static function () use ($cms): object {
            try {
                return (object) $cms->container->make(\Kestello\Cms\Menus\Menus::class)->forLocations();
            } catch (\Throwable $e) {
                error_log('[kestello] menus: ' . $e->getMessage());
                return (object) [];
            }
        });

        $cms->schedule->call(static fn() => $cms->container->make(Entries::class)->publishDue() . ' scheduled entries published.', 'content.publish-scheduled')
            ->everyMinute()->description('Publishes scheduled entries whose time has come.');
    }

    /**
     * @internal Kernel, after every package booted: capabilities, one menu
     * entry per type and the public routes (after the theme's and plugins'
     * own routes, so those win).
     */
    public static function finalize(Cms $cms): void
    {
        $cms->container->make(Models\ContentModels::class)->register();   // built in the admin; packages already declared theirs
        \Kestello\Cms\Site\SiteModule::contentFields($cms);      // "Search and sharing" on public types
        $cms->content->finalize($cms->auth->roles);
        $prefixed = [];
        $root = null;
        foreach ($cms->content->types() as $type) {
            $cms->admin->link(
                'core.content.' . $type->name,
                $type->plural(),
                '/admin/content/' . $type->name,
                icon: $type->getIcon(),
                group: 'content',
                order: $type->getMenuOrder(),
                capability: $type->capability('edit'),
            );
            if ($type->getPath() === '') {
                $root = $type;
            } elseif ($type->getPath() !== null) {
                $prefixed[] = $type;
            }
        }
        foreach ($cms->content->taxonomies() as $tax) {
            $cms->admin->link(
                'core.taxonomy.' . $tax->name,
                $tax->plural(),
                '/admin/taxonomies/' . $tax->name,
                icon: $tax->isHierarchical() ? 'folder' : 'tag',
                group: 'content',
                order: 500 + $tax->getMenuOrder(),
                capability: $tax->capability('manage'),
            );
        }
        $routes = $cms->routes;
        $routes->get('/preview/{token}', static fn(Request $r, string $token) => ContentController::preview($cms, $r, $token))
            ->where(['token' => '[A-Za-z0-9_-]{43}'])->name('content.preview');
        \Kestello\Cms\Site\SiteModule::contentRoutes($cms);      // /blog/feed before /blog/{path}
        foreach ($cms->content->taxonomies() as $tax) {
            if ($tax->getPath() !== null) {
                $taxName = $tax->name;
                $routes->get($tax->getPath() . '/{slug}', static fn(Request $r, string $slug) => ContentController::term($cms, $r, $taxName, $slug))
                    ->where(['slug' => '[a-z0-9][a-z0-9-]*'])->name("content.taxonomy.{$taxName}");
            }
        }
        // Longer prefixes first, so /blog/news doesn't swallow /blog/news-archive's type.
        usort($prefixed, static fn(ContentType $a, ContentType $b) => strlen((string) $b->getPath()) <=> strlen((string) $a->getPath()));
        foreach ($prefixed as $type) {
            $name = $type->name;
            if ($type->hasArchive()) {
                $routes->get((string) $type->getPath(), static fn(Request $r) => ContentController::archive($cms, $r, $name))->name("content.{$name}.archive");
            }
            $dated = Permalinks::supports($type);
            if ($dated) {       // /blog/2026 and /blog/2026/11, before /blog/{path}
                $routes->get($type->getPath() . '/{year}', static fn(Request $r, string $year) => ContentController::dated($cms, $r, $name, $year))
                    ->where(['year' => '[0-9]{4}'])->name("content.{$name}.year");
                $routes->get($type->getPath() . '/{year}/{month}', static fn(Request $r, string $year, string $month) => ContentController::dated($cms, $r, $name, $year, $month))
                    ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}'])->name("content.{$name}.month");
            }
            $routes->get($type->getPath() . '/{path}', static fn(Request $r, string $path) => ContentController::show($cms, $r, $name, $path))
                ->where(['path' => $type->isHierarchical() || $dated ? '[a-z0-9][a-z0-9/-]*' : '[a-z0-9][a-z0-9-]*'])->name("content.{$name}.show");
        }
        if (array_filter($cms->content->types(), static fn(ContentType $t) => $t->hasArchive()) !== []) {
            $routes->get('/author/{username}', static fn(Request $r, string $username) => ContentController::author($cms, $r, $username))
                ->where(['username' => '[A-Za-z0-9_-]{1,64}'])->name('content.author');
        }
        $routes->pushGlobalMiddleware(new FrontPageMiddleware($cms));      // Settings → Reading → Home page
        if ($root !== null) {
            $name = $root->name;
            $routes->get('/{path}', static fn(Request $r, string $path) => ContentController::show($cms, $r, $name, $path))
                ->where(['path' => '[a-z0-9][a-z0-9/-]*'])->name("content.{$name}.show");
        }
    }
}
