<?php

declare(strict_types=1);

namespace Themes\Exhibit;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\ThemeProvider;
use Themes\Exhibit\Controllers\HomeController;

/**
 * Exhibit: a studio's site hung as an exhibition. The home page is a theme
 * route; every other page is core's, drawn by the theme's own Entry and
 * Archive pages (resources/js/pages/@core/content).
 *
 * The rooms follow content types by name when a site has them: projects,
 * team, talks and the core post type (the journal). A site without them
 * still works; those rooms are left out.
 */
final class Theme extends ThemeProvider
{
    public function boot(Cms $cms): void
    {
        $cms->routes->get('/', static fn() => HomeController::home($cms))->name('home');

        $cms->menus->location('primary', 'Rooms', 'The numbered rooms across the top of every page.');
        $cms->menus->location('footer', 'Footer links', 'A row of links at the bottom of every page.');
    }
}
