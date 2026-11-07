<?php

declare(strict_types=1);

namespace Kestello\Cms\Search;

use Kestello\Cms\Cms;
use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\RateLimiter;

/**
 * GET /search?q=…&type=post&page=2 rendered by the theme's "@core/content/Search"
 * page. Searches are limited per visitor, since each one queries the engine.
 */
final class SearchController
{
    public const int PER_MINUTE = 30;

    public static function page(Cms $cms, Request $request): Response
    {
        $q = is_string($request->query['q'] ?? null) ? $request->query['q'] : '';
        $type = is_string($request->query['type'] ?? null) ? $request->query['type'] : '';
        $page = max(1, min(500, (int) ($request->query['page'] ?? 1)));
        $types = [];
        foreach ($cms->content->types() as $t) {
            if ($t->isSearchable()) {
                $types[] = ['value' => $t->name, 'label' => $t->plural()];
            }
        }

        $results = null;
        $limited = false;
        if (trim($q) !== '') {
            if (self::allowed($cms, $request)) {
                $results = $cms->search->search($q, ['types' => $type !== '' ? [$type] : [], 'page' => $page]);
            } else {
                $limited = true;
            }
        }

        return Page::render('@core/content/Search', [
            'title' => trim($q) !== '' ? 'Search: ' . mb_substr(trim($q), 0, 80) : 'Search',
            'query' => mb_substr($q, 0, 200),
            'type' => in_array($type, array_column($types, 'value'), true) ? $type : '',
            'types' => $types,
            'results' => $results,
            'limited' => $limited,
        ], $limited ? 429 : 200);
    }

    public static function allowed(Cms $cms, Request $request, string $bucket = 'page'): bool
    {
        $limiter = $cms->container->has(RateLimiter::class) ? $cms->container->make(RateLimiter::class) : null;

        return $limiter === null || $limiter->hit("search:{$bucket}:" . $request->ip(), self::PER_MINUTE, 60)->allowed;
    }
}
