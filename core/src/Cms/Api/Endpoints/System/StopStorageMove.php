<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Stop a move after the current file. Moved files stay moved; starting again carries on. */
final class StopStorageMove extends StorageEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/system/storage/move');
    }
    public function summary(): string
    {
        return 'Stop moving media';
    }

    public function handle(ApiRequest $request): mixed
    {
        $this->storage()->cancelMove($request->userId());

        return $this->overview();
    }
}
