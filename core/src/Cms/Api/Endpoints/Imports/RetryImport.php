<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Try the items that failed again. */
final class RetryImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/imports/{id}/retry')->where('id', self::ID);
    }

    public function summary(): string
    {
        return 'Try the items that failed again';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $this->id($request);

        return $this->attempt(fn() => $this->imports()->retry($id, $request->principal));
    }
}
