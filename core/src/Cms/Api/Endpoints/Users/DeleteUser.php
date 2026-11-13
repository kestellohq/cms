<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountDeletion;

/** Delete someone's account; their sessions, tokens and other per-person rows go with it (AccountDeletion). */
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
    public function problems(): array
    {
        return [...parent::problems(), 409];
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
        $refusal = AccountDeletion::refusal(self::cms(), $user, 'admin', $request->userId());
        if ($refusal !== null) {
            throw ApiProblem::conflict($refusal);
        }

        AccountDeletion::delete(self::cms(), $id, 'admin', $request->userId());
        self::audit($request, 'users.deleted', $id, ['username' => $user['username'], 'email' => $user['email']]);

        return null;
    }
}
