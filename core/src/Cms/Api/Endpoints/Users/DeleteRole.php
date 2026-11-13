<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\RoleStore;
use Kestello\Framework\Database\QueryBuilder;

/**
 * A custom role: delete it (people who have it move to ?reassign=, default
 * "user"). A role declared in code: undo the changes made here.
 */
final class DeleteRole extends Endpoint
{
    use RoleAdmin;

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/roles/{name}')->where('name', '[a-z][a-z0-9_-]{0,19}');
    }
    public function capability(): ?string
    {
        return 'roles.manage';
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
        return 'Delete a custom role, or reset a built-in one';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function query(): ?Schema
    {
        return Schema::object(['reassign' => Schema::string()->max(20)->pattern('[a-z][a-z0-9_-]{0,19}')->default('user')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = (string) $request->param('name');
        self::editableRole($request, $name);
        $roles = self::roles();
        if (!$roles->isCustom($name)) {
            if (!$roles->isCustomized($name)) {
                throw ApiProblem::conflict('This role already has its default capabilities.');
            }
            RoleStore::forget($roles, $name);
            self::cms()->audit->record('role.reset', ['role', $name], [], $request->userId());

            return self::describeRole($name, $request);
        }
        $target = (string) $request->query('reassign');
        if ($target === $name || !$roles->exists($target)) {
            throw ApiProblem::validation(['reassign' => ['Pick another existing role for the people who have this one.']]);
        }
        self::guardSuper($request, $target);
        $moved = QueryBuilder::table('users')->where('role', $name)->update(['role' => $target]);
        RoleStore::forget($roles, $name);
        self::cms()->audit->record('role.deleted', ['role', $name], ['reassigned' => $moved, 'to' => $target], $request->userId());

        return null;
    }
}
