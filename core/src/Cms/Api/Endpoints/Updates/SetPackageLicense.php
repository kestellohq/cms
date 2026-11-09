<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Save the license key of a paid plugin or theme, then ask its feed with it. The key is kept
 * sealed and never shown again (only its last four characters); it goes only to the package's feed.
 */
final class SetPackageLicense extends PackageUpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/updates/packages/license');
    }

    public function summary(): string
    {
        return 'Save a plugin or theme license key';
    }

    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function rateLimit(): ?array
    {
        return [10, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object(['owner' => self::ownerSchema(), 'key' => Schema::string()->max(500)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $owner = (string) $request->input('owner');
        $this->source($owner);
        try {
            $this->licenses()->set($owner, (string) $request->input('key'));
        } catch (\InvalidArgumentException $e) {
            throw ApiProblem::validation(['key' => [$e->getMessage()]]);
        }
        $this->cms()->audit->record('package.license_set', explode(':', $owner, 2), [], $request->userId());
        $this->updates()->check(only: $owner);

        return $this->versions();
    }
}
