<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Forget an import (what it imported stays). */
final class DeleteImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/imports/{id}')->where('id', self::ID);
    }

    public function summary(): string
    {
        return 'Forget an import (what it imported stays)';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $this->id($request);
        $this->attempt(fn() => $this->imports()->discard($id, $request->principal));

        return ['deleted' => $id];
    }
}
