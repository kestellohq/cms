<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Do the next part of a running import (sites without a queue worker). */
final class StepImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/imports/{id}/step')->where('id', self::ID);
    }

    public function summary(): string
    {
        return 'Do the next part of a running import (sites without a queue worker)';
    }

    public function rateLimit(): ?array
    {
        return [120, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->imports()->step($this->id($request), 8.0);
    }
}
