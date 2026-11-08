<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/**
 * Go back to the Kestello version before the last update: its release
 * (still on the server) and the database backup taken before the update.
 * A backup of the site as it is now is taken first. The next request runs
 * the old release and restores the database; "switch_back" says from and to.
 */
final class SwitchBackCore extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/core/switch-back');
    }
    public function summary(): string
    {
        return 'Switch Kestello back to the version before the last update';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [5, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $result = $this->attempt(fn() => $this->recovery()->switchBack('core', $this->core()->running(), $request->userId()));

        return ['switch_back' => $result] + $this->versions();
    }
}
