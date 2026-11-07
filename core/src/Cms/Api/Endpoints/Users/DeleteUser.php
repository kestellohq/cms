<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Framework\Database\QueryBuilder;

/** Delete someone's account; their sessions and tokens end with it. */
final class DeleteUser extends Endpoint
{
    use UserAdmin;

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/users/{id}')->where('id', '\d+');
    }
    public function capability(): ?string
    {
        return 'users.manage';
    }
    public function scopes(): array
    {
        return ['users:write'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function summary(): string
    {
        return 'Delete a user';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $user = self::target($id);
        if ($id === $request->userId()) {
            throw ApiProblem::conflict('You cannot delete your own account.');
        }
        self::guardSuper($request, (string) $user['role']);
        if (self::isSuperRole((string) $user['role']) && self::otherActiveSupers($id) === 0) {
            throw ApiProblem::conflict('This is the last administrator.');
        }

        self::endAccess($id);
        QueryBuilder::table('users')->where('id', $id)->delete();
        self::audit($request, 'users.deleted', $id, ['username' => $user['username'], 'email' => $user['email']]);

        return null;
    }
}
