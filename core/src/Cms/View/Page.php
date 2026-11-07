<?php

declare(strict_types=1);

namespace Kestello\Cms\View;

use Kestello\Framework\Core\Response;

/**
 * Render a Vue page from a controller:
 *
 *   return Page::render('Blog/Post', ['post' => PostResource::make($post)]);
 *
 * Component names:
 *   'Home', 'Blog/Post'   theme pages (child theme → parent theme)
 *   '@core/admin/Users'   core pages      ─┐ a theme can override these by
 *   '@acme-seo/Report'    plugin pages    ─┘ shipping the same name
 *
 * Props are serialized to the browser. Pass only what the page needs: select
 * columns explicitly, never whole database rows.
 */
final class Page
{
    /**
     * @param array<string, mixed> $props
     * @param list<string> $preload other components the page renders (block components)
     */
    public static function render(string $component, array $props = [], int $status = 200, array $preload = []): Response
    {
        return Response::deferred(new PageResponse($component, $props, $status, $preload), $status);
    }
}
