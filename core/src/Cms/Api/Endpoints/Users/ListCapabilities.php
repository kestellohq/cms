<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;

/** Every declared capability, grouped by the package that declared it. */
final class ListCapabilities extends Endpoint
{
    use RoleAdmin;

    public function route(): ApiRoute
    {
        return ApiRoute::get('/capabilities');
    }
    public function capability(): ?string
    {
        return 'roles.manage';
    }
    public function scopes(): array
    {
        return ['users:read'];
    }
    public function summary(): string
    {
        return 'List capabilities';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $super = self::actorIsSuper($request);
        $out = [];
        foreach (self::roles()->capabilities() as $name => $cap) {
            $out[] = ['name' => $name, 'label' => $cap['label'], 'owner' => $cap['owner'], 'sensitive' => $cap['sensitive'], 'grantable' => $super || $request->principal->can($name)];
        }

        return $out;
    }
}
