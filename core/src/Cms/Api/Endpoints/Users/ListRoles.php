<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;

/** Roles you can assign, with what each grants. */
final class ListRoles extends Endpoint
{
    use RoleAdmin;

    public function route(): ApiRoute
    {
        return ApiRoute::get('/roles');
    }
    public function capability(): ?string
    {
        return 'admin.access';
    }
    public function scopes(): array
    {
        return ['users:read'];
    }
    public function summary(): string
    {
        return 'List roles';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function authorize(ApiRequest $request): bool
    {
        // The People screen (users.read) and the Roles screen (roles.manage) both list roles.
        return $request->principal->can('users.read') || $request->principal->can('roles.manage');
    }

    public function handle(ApiRequest $request): mixed
    {
        return array_map(static fn(string $name) => self::describeRole($name, $request), array_keys(self::roles()->roles()));
    }
}
