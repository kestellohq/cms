<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Move every media file to another disk. New uploads go there at once;
 * existing files follow, verified one by one. Without a queue worker the
 * admin screen drives the move with /system/storage/move/step.
 */
final class StartStorageMove extends StorageEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/storage/move');
    }
    public function summary(): string
    {
        return 'Move media to another disk';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'to' => Schema::string()->max(64)->pattern('[a-z0-9][a-z0-9_-]*'),
            'keep_source' => Schema::bool()->default(false),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        self::attempt(fn() => $this->storage()->startMove((string) $request->body['to'], (bool) ($request->body['keep_source'] ?? false), $request->userId()));

        return $this->overview();
    }
}
