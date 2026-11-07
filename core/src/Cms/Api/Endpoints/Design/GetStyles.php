<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** The running theme's design tokens, style variations and the site's published choices. */
final class GetStyles extends DesignEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/design/styles');
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function summary(): string
    {
        return 'Global styles';
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->overview($request);
    }
}
