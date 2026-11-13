<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use DateTimeImmutable;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRegistry;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\TokenResource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;

/**
 * Create a personal access token. The plain token is in the response once
 * and never again. Needs a recent interactive login (tokens can't mint tokens).
 */
final class CreateMyToken extends Endpoint
{
    public const int MAX_TOKENS = 50;

    public function __construct(private readonly PersonalAccessTokens $tokens) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/me/tokens');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 3600];
    }
    public function resource(): ?string
    {
        return TokenResource::class;
    }
    public function status(): int
    {
        return 201;
    }

    public function summary(): string
    {
        return 'Create a personal access token';
    }

    public function body(): ?Schema
    {
        $scopes = array_keys(Cms::instance()?->api->scopes() ?? ApiRegistry::CORE_SCOPES);

        return Schema::object([
            'name' => Schema::string()->min(1)->max(100),
            'scopes' => Schema::list(Schema::enum($scopes))->min(1)->max(30),
            'expires_in_days' => Schema::int()->min(1)->max(365)->nullable()->default(90)
                ->description('Days until expiry; null for a token that never expires'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();

        if (count($this->tokens->forUser($userId)) >= self::MAX_TOKENS) {
            throw ApiProblem::validation(['name' => ['You have too many tokens; revoke some first.']]);
        }

        $days = $request->input('expires_in_days');
        $created = $this->tokens->create(
            $userId,
            (string) $request->input('name'),
            (array) $request->input('scopes'),
            $days === null ? null : new DateTimeImmutable('+' . (int) $days . ' days'),
        );

        Cms::instance()?->events->dispatch(new \Kestello\Cms\Events\TokenCreated(
            $userId,
            (int) $created['record']['id'],
            (string) $created['record']['name'],
            (array) $request->input('scopes'),
        ));
        $data = (new TokenResource($created['record']))->resolve($request->principal);

        return Response::json(['data' => $data + ['token' => $created['token']]], 201);
    }
}
