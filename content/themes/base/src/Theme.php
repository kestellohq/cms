<?php

declare(strict_types=1);

namespace Themes\Base;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\ThemeProvider;
use Themes\Base\Controllers\PageController;

/**
 * Base theme: public page routes. Pages and layouts are Vue SFCs in
 * resources/js (built into assets/build with "npm run build").
 *
 * The home and about pages are theme routes; everything else comes from
 * content types. Menus built in the admin fill the locations below.
 */
final class Theme extends ThemeProvider
{
    public function boot(Cms $cms): void
    {
        $cms->routes->get('/', [PageController::class, 'home'])->name('home');
        $cms->routes->get('/about', [PageController::class, 'about'])->name('about');

        $cms->menus->location('primary', 'Main navigation', 'Across the top of every page.');
        $cms->menus->location('footer', 'Footer links', 'A row of links at the bottom of every page.');
    }
}
