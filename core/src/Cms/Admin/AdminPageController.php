<?php

declare(strict_types=1);

namespace Kestello\Cms\Admin;

use Kestello\Cms\Cms;
use Kestello\Cms\I18n\BrowserCatalogue;
use Kestello\Cms\I18n\Labels;
use Kestello\Cms\View\Page;
use Kestello\Cms\View\PageResponse;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Support\Translator;

/** Renders a registered admin page with the admin boot data. @internal */
final class AdminPageController
{
    /**
     * @param array{path: string, component: string, title: string, capability: string, owner: string} $page
     * @param array<string, string> $params
     */
    public static function render(Cms $cms, Request $request, array $page, array $params): Response
    {
        $principal = \Kestello\Cms\Auth\AdminMiddleware::principal($cms, $request);
        if ($principal->isGuest()) {
            \Kestello\Cms\Auth\IntendedUrl::remember($request);
            return Response::redirect('/login');
        }
        if (!$principal->can('admin.access') || !$principal->can($page['capability'])) {
            return Page::render('@core/errors/Error', \Kestello\Cms\Frontend\ErrorPage::props(403), 403);
        }

        return Page::render($page['component'], [
            'title' => Labels::text($page['title'], Labels::domain($page['owner'])),
            'params' => $params,
            'admin' => self::boot($cms, $principal),
        ]);
    }

    /**
     * The page for an admin address that leads nowhere (a mistyped link, a turned-off
     * plugin's page): in the admin, with its menu, for people who may use the admin.
     * Null for anyone else, who gets the site's own "not found" page.
     */
    public static function notFound(Cms $cms, Request $request): ?PageResponse
    {
        $principal = \Kestello\Cms\Auth\AdminMiddleware::principal($cms, $request);
        if ($principal->isGuest() || !$principal->can('admin.access')) {
            return null;
        }

        return new PageResponse('@core/admin/NotFound', ['title' => Translator::get('Page not found'), 'admin' => self::boot($cms, $principal)], 404);
    }

    /** @return array<string, mixed> public data only: everything here reaches the browser */
    public static function boot(Cms $cms, Principal $principal): array
    {
        return [
            'menu' => $cms->admin->menuFor($principal),
            'extensions' => $cms->admin->extensionsFor($principal),
            'capabilities' => $principal->capabilities,
            'api' => '/api/v1',
            'version' => $cms->version(),
            'locale' => Translator::locale(),
            'dir' => Translator::direction(),
            'translations' => BrowserCatalogue::url(Translator::locale()),
        ];
    }
}
