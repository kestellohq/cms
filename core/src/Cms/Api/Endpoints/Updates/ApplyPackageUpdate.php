<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\Update\Updater;

/**
 * Apply a reviewed plugin or theme update (POST /updates/packages/download): backup, swap,
 * health check, and an automatic rollback when the new version is not healthy.
 * "result.ok" says which happened.
 */
final class ApplyPackageUpdate extends PackageUpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/packages/update');
    }

    public function summary(): string
    {
        return 'Update a plugin or theme from its source (with backup and automatic rollback)';
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
        return Schema::object(['token' => Schema::string()->max(32), 'owner' => self::ownerSchema()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $token = (string) $request->input('token');
        $owner = (string) $request->input('owner');
        $zip = $this->attempt(fn() => $this->installer()->held($token));
        $updater = $this->cms()->container->make(Updater::class);
        $check = $this->attempt(fn() => $updater->check($zip));
        if ($check['type'] . ':' . $check['slug'] !== $owner) {
            throw ApiProblem::validation(['token' => ['This download is for another package.']]);
        }
        $result = $this->attempt(fn() => $updater->update($zip, [
            'allow_unsigned' => $this->installer()->adminMayInstallUnsigned(),
            'actor' => $request->userId(),
        ]));
        $this->installer()->release($token);

        return ['result' => $result] + $this->versions();
    }
}
