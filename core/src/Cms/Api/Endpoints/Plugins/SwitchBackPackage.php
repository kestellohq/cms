<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\Update\UpdateRecovery;

/**
 * Go back to the version of a plugin or theme before its last update: the
 * old folder (kept on the server) and the database backup taken before the
 * update. A backup of the site as it is now is taken first; the next
 * request puts the old version back, before plugins load.
 */
final class SwitchBackPackage extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/switch-back');
    }
    public function summary(): string
    {
        return 'Switch a plugin or theme back to the version before its last update';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [5, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object(['owner' => Schema::string()->max(80)->pattern('^(plugin|theme):' . self::SLUG . '$')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $owner = (string) $request->input('owner');
        $running = $this->installedVersion($owner) ?? throw ApiProblem::notFound('There is no plugin or theme with that name.');
        $result = $this->attempt(fn() => $this->cms()->container->make(UpdateRecovery::class)->switchBack($owner, $running, $request->userId()));

        return ['switch_back' => $result] + $this->overview();
    }
}
