<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\External\Identities;
use Kestello\Cms\Auth\External\IdentityResolver;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\IdentityUnlinked;
use Kestello\Framework\Database\QueryBuilder;

/** Disconnect an external account. Refused when it is the only way left to sign in. */
final class UnlinkMyIdentity extends Endpoint
{
    use SessionOnly;

    public function __construct(private readonly Identities $identities) {}

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/me/identities/{id}')->where('id', '[0-9]+');
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
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Disconnect a sign-in account';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();
        $id = (int) $request->param('id');
        $mine = array_column($this->identities->forUser($userId), null, 'id');
        if (!isset($mine[$id])) {
            throw ApiProblem::notFound('No such connected account.');
        }

        $user = QueryBuilder::table('users')->where('id', $userId)->first() ?? [];
        if (count($mine) === 1 && !IdentityResolver::hasUsablePassword($user)) {
            throw ApiProblem::conflict('This is your only way to sign in. Set a password first (use "Forgot password").');
        }

        $this->identities->unlink($userId, $id);
        Cms::instance()?->events->dispatch(new IdentityUnlinked($userId, (string) $mine[$id]['provider'], $id));

        return null;
    }
}
