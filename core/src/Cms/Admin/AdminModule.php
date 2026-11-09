<?php

declare(strict_types=1);

namespace Kestello\Cms\Admin;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\I18n\BrowserCatalogue;
use Kestello\Framework\Core\Request;

/**
 * Core module: the admin app. Pages registered with $cms->admin->page()
 * become routes here (core and plugins alike). Every screen talks to the
 * v1 API; nothing here renders server forms.
 */
final class AdminModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $admin = $cms->admin;
        $routes = $cms->routes;
        $admin->setRouter(static function (array $page) use ($cms, $routes): void {
            $routes->get($page['path'], static fn(Request $request, string ...$params) => AdminPageController::render($cms, $request, $page, $params))
                ->name('admin.page.' . trim(str_replace(['/', '{', '}'], ['.', '', ''], $page['path']), '.'))
                ->where($page['where'] ?? []);
        });

        // the admin's translations for the browser (public: they are the same text the admin shows)
        $routes->get(BrowserCatalogue::PATH . '{locale}', static fn(Request $request, string $locale) => BrowserCatalogue::respond($locale, $request->query['v'] ?? null, ($request->query['site'] ?? '') === '1'))
            ->name('admin.translations');

        $admin->page('', '@core/admin/Dashboard', 'Overview', menu: ['label' => 'Overview', 'icon' => 'house', 'group' => 'main', 'order' => 0]);
        $admin->page('users', '@core/admin/users/Index', 'People', 'users.read', ['label' => 'People', 'icon' => 'users', 'group' => 'people', 'order' => 10]);
        $admin->page('users/{id}', '@core/admin/users/Edit', 'Person', 'users.read', where: ['id' => AdminRegistry::ID]);
        $admin->page('roles', '@core/admin/roles/Index', 'Roles', 'roles.manage', ['label' => 'Roles', 'icon' => 'shield-check', 'group' => 'people', 'order' => 20]);
        $admin->page('profile', '@core/admin/Profile', 'Your profile', 'profile.manage', ['label' => 'Your profile', 'icon' => 'user-circle', 'group' => 'people', 'order' => 90]);
        $admin->page('system/health', '@core/admin/system/Health', 'Site Health', 'system.manage', ['label' => 'Site Health', 'icon' => 'heartbeat', 'group' => 'system', 'order' => 10]);
        $admin->page('system/platform', '@core/admin/system/Platform', 'Infrastructure', 'system.manage', ['label' => 'Infrastructure', 'icon' => 'hard-drives', 'group' => 'system', 'order' => 90]);
        $admin->page('system/queue', '@core/admin/system/Queue', 'Background jobs', 'system.manage', ['label' => 'Background jobs', 'icon' => 'stack', 'group' => 'system', 'order' => 60]);
        $admin->page('system/schedule', '@core/admin/system/Schedule', 'Scheduled tasks', 'system.manage', ['label' => 'Scheduled tasks', 'icon' => 'clock-counter-clockwise', 'group' => 'system', 'order' => 70]);
        $admin->page('system/security', '@core/admin/system/Security', 'Security', 'security.manage', ['label' => 'Security', 'icon' => 'lock-key', 'group' => 'system', 'order' => 20]);
        $admin->page('system/audit', '@core/admin/system/Audit', 'Audit log', 'audit.read', ['label' => 'Audit log', 'icon' => 'notebook', 'group' => 'system', 'order' => 30]);
        $admin->page('system/api', '@core/admin/system/Api', 'API', 'admin.access', ['label' => 'API', 'icon' => 'brackets-curly', 'group' => 'system', 'order' => 55]);
        $admin->page('settings', '@core/admin/Settings', 'Settings', 'settings.manage', ['label' => 'Settings', 'icon' => 'gear', 'group' => 'site', 'order' => 10]);
    }

}
