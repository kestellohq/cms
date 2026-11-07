<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\RoleStore;

/**
 * Change a role's label, description or capabilities (the full list).
 * People with the role get the new capabilities on their next request.
 */
final class UpdateRole extends Endpoint
{
    use RoleAdmin;

    public function route(): ApiRoute
    {
        return ApiRoute::patch('/roles/{name}')->where('name', '[a-z][a-z0-9_-]{0,19}');
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
    public function summary(): string
    {
        return 'Update a role';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'label' => Schema::string()->min(1)->max(60)->optional(),
            'description' => Schema::string()->max(255)->nullable()->optional(),
            'capabilities' => Schema::list(Schema::string()->max(100))->max(500)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = (string) $request->param('name');
        self::editableRole($request, $name);
        $roles = self::roles();
        $before = $roles->capabilitiesFor($name);
        $caps = $request->has('capabilities') ? self::checkCapabilities($request, (array) $request->body['capabilities'], $name) : $before;
        $label = $request->has('label') ? trim((string) $request->body['label']) : ($roles->roles()[$name]['label'] ?? $name);
        $description = $request->has('description') ? $request->body['description'] : $roles->description($name);
        RoleStore::save($roles, $name, $label, $description === '' ? null : $description, $caps, custom: $roles->isCustom($name));
        self::cms()->audit->record('role.updated', ['role', $name], [
            'granted' => array_values(array_diff($caps, $before)), 'revoked' => array_values(array_diff($before, $caps)),
        ], $request->userId());

        return self::describeRole($name, $request);
    }
}
