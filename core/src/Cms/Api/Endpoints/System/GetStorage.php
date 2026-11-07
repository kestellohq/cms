<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Where media files are kept, what each disk holds, upload quotas and the current move. */
final class GetStorage extends StorageEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/storage');
    }
    public function summary(): string
    {
        return 'Media storage overview';
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->overview();
    }
}
