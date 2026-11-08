<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\Prerender\PrerenderStore;

/**
 * Pages waiting for their saved HTML (View\Prerender\PrerenderStore), with
 * what a browser needs to draw each: the page data, the address of every
 * component's SSR build and the messages. The admin draws them in a hidden
 * frame (/system/prerender) and sends the HTML back.
 */
final class ListWaitingPages extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/prerender');
    }
    public function summary(): string
    {
        return 'List the pages waiting for saved HTML';
    }

    public function query(): ?Schema
    {
        return Schema::object(['limit' => Schema::int()->min(1)->max(10)->default(3)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $store = $this->store();
        if ($store === null) {
            return ['enabled' => false, 'saved' => 0, 'waiting' => 0, 'pages' => []];
        }
        $container = $this->cms()->container;
        $version = $container->has(PageRenderer::class) ? $container->make(PageRenderer::class)->registry()->version() : null;
        $limit = (int) $request->query('limit', 3);
        $pages = [];
        foreach ($store->waiting($limit) as $item) {
            // built for another version of the site's code: its modules are gone, and visitors get new data anyway
            if ($version !== null && ($item['page']['version'] ?? null) !== $version) {
                $store->forget($item['key']);
                continue;
            }
            $pages[] = $item;
        }

        return ['enabled' => true] + $store->counts() + ['pages' => $pages];
    }

    private function store(): ?PrerenderStore
    {
        return $this->cms()->container->has(PrerenderStore::class) ? $this->cms()->container->make(PrerenderStore::class) : null;
    }
}
