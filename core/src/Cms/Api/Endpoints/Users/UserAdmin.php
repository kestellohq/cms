<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Cms;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Shared rules for managing other people's accounts.
 *
 * - You can only give out, change or remove accounts whose role has no
 *   capability you lack; roles holding "*" need "*" (no privilege
 *   escalation through users.manage).
 * - You cannot demote, deactivate or delete yourself here (use your profile).
 * - The last active administrator always stays.
 *
 * @internal
 */
trait UserAdmin
{
    private static function cms(): Cms
    {
        return Cms::instance() ?? throw ApiProblem::server('Kestello is not booted.');
    }

    private static function isSuperRole(string $role): bool
    {
        return in_array('*', self::cms()->auth->roles->capabilitiesFor($role), true);
    }

    private static function actorIsSuper(ApiRequest $request): bool
    {
        return in_array('*', $request->principal->capabilities, true);
    }

    /** @return array<string, mixed> the target row */
    private static function target(int $id): array
    {
        return QueryBuilder::table('users')->where('id', $id)->first() ?? throw ApiProblem::notFound('No such user.');
    }

    /**
     * The actor may give out, or act on accounts with, $role only when they
     * hold every capability it has. Otherwise users.manage would be a way to
     * create (or take over) an account more powerful than your own.
     */
    private static function guardSuper(ApiRequest $request, string $role): void
    {
        if (self::actorIsSuper($request)) {
            return;
        }
        if (self::isSuperRole($role)) {
            throw ApiProblem::forbidden('Only an administrator can manage administrator accounts.');
        }
        $missing = array_values(array_filter(
            self::cms()->auth->roles->capabilitiesFor($role),
            static fn(string $cap): bool => !$request->principal->can($cap),
        ));
        if ($missing !== []) {
            throw ApiProblem::forbidden("You can't manage people with the role \"{$role}\": it has capabilities you don't (" . implode(', ', $missing) . ').');
        }
    }

    private static function otherActiveSupers(int $exceptId): int
    {
        $roles = array_keys(array_filter(self::cms()->auth->roles->roles(), static fn(array $r) => in_array('*', $r['capabilities'], true)));

        return $roles === [] ? 0 : QueryBuilder::table('users')->whereIn('role', $roles)->where('is_active', 1)->where('id', '!=', $exceptId)->count();
    }

    /** @param array<string, mixed> $data */
    private static function audit(ApiRequest $request, string $event, int $userId, array $data = []): void
    {
        self::cms()->audit->record($event, ['user', $userId], $data, $request->userId());
    }

    private static function endAccess(int $userId): void
    {
        $c = self::cms()->container;
        if ($c->has(\Kestello\Contracts\Session\SessionStore::class)) {
            $c->make(\Kestello\Contracts\Session\SessionStore::class)->destroyForUser($userId);
        }
        $c->make(\Kestello\Cms\Auth\PersonalAccessTokens::class)->revokeAll($userId);
    }
}
