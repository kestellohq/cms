<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** Every rule as CSV (source,target,status,match,note). */
final class ExportRedirects extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/redirects/export');
    }
    public function summary(): string
    {
        return 'Export redirects as CSV';
    }

    public function handle(ApiRequest $request): mixed
    {
        return (new Response(self::redirects()->export(), 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="redirects-' . gmdate('Y-m-d') . '.csv"');
    }
}
