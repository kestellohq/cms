<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Contracts\Cache\PageCache;

/** Empty the page cache (it refills as visitors arrive). Anyone who can change settings may. */
final class ClearPageCache extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/page-cache/clear');
    }
    public function capability(): ?string
    {
        return 'settings.manage';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function summary(): string
    {
        return 'Clear the page cache';
    }

    public function handle(ApiRequest $request): mixed
    {
        $this->cms()->container->make(PageCache::class)->flush();
        $this->cms()->audit->record('cache.cleared', ['cache', 'pages'], [], $request->userId());

        return ['cleared' => true];
    }
}
