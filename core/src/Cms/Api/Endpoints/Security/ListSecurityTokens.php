<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\SecurityTokenResource;

/** Every personal access token, with its owner and scopes. Never the secret. */
final class ListSecurityTokens extends SecurityEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/security/tokens');
    }
    public function resource(): ?string
    {
        return SecurityTokenResource::class;
    }
    public function summary(): string
    {
        return 'List everyone\'s access tokens';
    }

    public function handle(ApiRequest $request): mixed
    {
        return SecurityTokenResource::collection(self::overview()->tokens());
    }
}
