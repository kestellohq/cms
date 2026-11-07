<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Move the next few files (for sites without a queue worker; the screen calls this while it is open). */
final class StepStorageMove extends StorageEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/storage/move/step');
    }
    public function summary(): string
    {
        return 'Move the next media files';
    }
    public function rateLimit(): ?array
    {
        return [120, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $this->storage()->step(maxFiles: 25, seconds: 10.0);

        return $this->overview();
    }
}
