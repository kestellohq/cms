<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;

/** Site Health report (cached for 5 minutes; refresh=1 runs the checks again). */
final class GetSiteHealth extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/health');
    }
    public function summary(): string
    {
        return 'Run the Site Health checks';
    }

    public function query(): ?Schema
    {
        return Schema::object(['refresh' => Schema::bool()->default(false)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return Response::json(['data' => $this->cms()->health->run(fresh: $request->query('refresh') === true)]);
    }
}
