<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\TokenResource;
use Kestello\Cms\Auth\PersonalAccessTokens;

final class ListMyTokens extends Endpoint
{
    public function __construct(private readonly PersonalAccessTokens $tokens) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/tokens');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function resource(): ?string
    {
        return TokenResource::class;
    }
    public function summary(): string
    {
        return 'Your personal access tokens';
    }

    public function handle(ApiRequest $request): mixed
    {
        $scopes = [];
        foreach (\Kestello\Cms\Cms::instance()?->api->scopes() ?? [] as $name => $scope) {
            $scopes[] = ['name' => $name, 'description' => $scope['description']];
        }

        return TokenResource::collection($this->tokens->forUser((int) $request->userId()), ['scopes' => $scopes]);
    }
}
