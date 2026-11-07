<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** An import: what it found, what it did and what it could not do. */
final class GetImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/imports/{id}')->where('id', self::ID);
    }

    public function summary(): string
    {
        return 'An import: what it found, what it did and what it could not do';
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::present((array) $this->imports()->find($this->id($request)));
    }
}
