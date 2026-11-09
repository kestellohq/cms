<?php

declare(strict_types=1);

namespace Kestello\Cms\Installer;

use Kestello\Cms\Extension\AssetController;
use Kestello\Cms\Frontend\Csp;
use Kestello\Cms\Frontend\FrontendMiddleware;
use Kestello\Cms\View\PageRegistry;
use Kestello\Cms\View\PageRenderer;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Security\Csrf;
use Kestello\Framework\Security\CsrfMiddleware;
use Kestello\Framework\Session\Session;
use Kestello\Kernel;

/**
 * Installer routes. The CMS is not booted yet, so the installer gets its own
 * minimal page renderer (core pages only, no plugins or themes).
 */
final class InstallerRoutes
{
    public static function register(Router $router, Paths $paths, bool $secureCookies = false): void
    {
        // A real session (same hardening as the site) so the forms carry a CSRF token.
        $session = new Session(['name' => 'kestello_install', 'secure' => $secureCookies]);
        $session->start();

        $controller = new InstallerController($paths);
        $csp = new Csp();
        $renderer = new PageRenderer(
            registry: new PageRegistry($paths, null, Kernel::version()),
            csp: $csp,
            paths: $paths,
            shared: static fn(): array => ['site' => ['name' => 'Kestello', 'url' => ''], 'auth' => ['user' => null], 'csrfToken' => $session->token(), 'flash' => [], 'errors' => (object) [], 'old' => (object) []],
            head: static fn(): string => '',
        );
        $router->pushGlobalMiddleware(new FrontendMiddleware($csp, $renderer));
        $router->pushGlobalMiddleware(new CsrfMiddleware(new Csrf($session)));

        $router->get('/install', fn(Request $r) => $controller->index($r))->name('installer.index');
        $router->post('/install', fn(Request $r) => $controller->process($r))->name('installer.process');
        $router->get('/install/complete', fn(Request $r) => $controller->complete($r))->name('installer.complete');
    }

    /**
     * Core assets (Vue, the installer's scripts and styles) before installation.
     * Nothing else is served then: no session, no plugin or theme assets.
     */
    public static function assets(Router $router, Paths $paths): void
    {
        $root = $paths->coreAssets();
        $router->get('/assets/core/{path}', static fn(Request $r, string $path) => (new AssetController())->coreFrom($root, $r, $path))
            ->where(['path' => '.+'])
            ->name('assets.core');
    }
}
