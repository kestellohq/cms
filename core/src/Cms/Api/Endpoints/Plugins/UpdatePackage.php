<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\Update\Updater;

/**
 * Update an installed plugin from a reviewed upload: backup, swap, health
 * check, and an automatic rollback when the new version is not healthy.
 * "result.ok" says which happened.
 */
final class UpdatePackage extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/update');
    }
    public function summary(): string
    {
        return 'Update a plugin (with backup and automatic rollback)';
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
        return Schema::object(['token' => Schema::string()->max(32)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $token = (string) $request->input('token');
        $zip = $this->attempt(fn() => $this->installer()->held($token));
        $result = $this->attempt(fn() => $this->cms()->container->make(Updater::class)->update($zip, [
            'allow_unsigned' => $this->installer()->adminMayInstallUnsigned(),
            'actor' => $request->userId(),
        ]));
        $this->installer()->release($token);

        return ['result' => $result] + $this->overview();
    }
}
