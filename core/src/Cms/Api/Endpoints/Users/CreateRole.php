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

/** Create a role (it starts with the capabilities you pick). */
final class CreateRole extends Endpoint
{
    use RoleAdmin;

    public function route(): ApiRoute
    {
        return ApiRoute::post('/roles');
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
        return 'Create a role';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'name' => Schema::string()->min(2)->max(20)->pattern('^[a-z][a-z0-9_-]{1,19}$')->description('Lowercase id, e.g. "reviewer"'),
            'label' => Schema::string()->min(1)->max(60),
            'description' => Schema::string()->max(255)->nullable()->optional(),
            'capabilities' => Schema::list(Schema::string()->max(100))->max(500)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = (string) $request->body['name'];
        if (self::roles()->exists($name) || QueryBuilder::table('roles')->where('name', $name)->exists()) {
            throw ApiProblem::validation(['name' => ['A role with this name already exists.']]);
        }
        $caps = self::checkCapabilities($request, (array) ($request->body['capabilities'] ?? []));
        RoleStore::save(self::roles(), $name, trim((string) $request->body['label']), $request->body['description'] ?? null, $caps, custom: true);
        self::cms()->audit->record('role.created', ['role', $name], ['capabilities' => $caps], $request->userId());

        return self::describeRole($name, $request);
    }
}
