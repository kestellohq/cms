<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\View\PageRenderer;
use Kestello\Cms\View\Prerender\PrerenderStore;
use Kestello\Contracts\Cache\PageCache;

/**
 * Save the HTML a browser drew for a waiting page. Only a page that is
 * waiting is accepted: its key is the page data, so HTML can only be saved
 * for data the server itself produced. The cached copy of the page is
 * dropped, so the next visitor gets the HTML.
 */
final class SavePageHtml extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/prerender/{key}')->where('key', '[a-f0-9]{64}');
    }
    public function summary(): string
    {
        return 'Save the HTML drawn for a waiting page';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'html' => Schema::string()->max(PrerenderStore::MAX_BYTES),
            'title' => Schema::string()->max(300)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $container = $this->cms()->container;
        if (!$container->has(PrerenderStore::class)) {
            throw ApiProblem::notFound('Saved page HTML is turned off on this site.');
        }
        $key = (string) $request->param('key');
        try {
            $url = $container->make(PrerenderStore::class)->save($key, (string) $request->input('html'), (string) $request->input('title', ''));
        } catch (\InvalidArgumentException $e) {
            throw ApiProblem::validation(['html' => [$e->getMessage()]]);
        }
        if ($container->has(PageCache::class)) {
            $container->make(PageCache::class)->purge(PageRenderer::tag($key));
        }

        return ['saved' => true, 'url' => $url];
    }
}
