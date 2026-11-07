<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Apply a reviewed core release: backup, switch, health check, automatic switch back. "result.ok" says which happened. */
final class UpdateCore extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/core');
    }
    public function summary(): string
    {
        return 'Update Kestello (with backup and automatic rollback)';
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
        $result = $this->attempt(fn() => $this->core()->update($zip, ['allow_unsigned' => $this->installer()->adminMayInstallUnsigned(), 'actor' => $request->userId()]));
        $this->installer()->release($token);

        return ['result' => $result] + $this->versions();
    }
}
