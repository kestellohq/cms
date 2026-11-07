<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Stop a running import; what was imported stays. */
final class CancelImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/imports/{id}/cancel')->where('id', self::ID);
    }

    public function summary(): string
    {
        return 'Stop a running import; what was imported stays';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $this->id($request);

        return $this->attempt(fn() => $this->imports()->cancel($id, $request->principal));
    }
}
