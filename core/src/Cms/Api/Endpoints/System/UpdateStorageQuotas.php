<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** How much each role may upload, in megabytes (null: no limit). Administrators are never limited. */
final class UpdateStorageQuotas extends StorageEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/system/storage/quotas');
    }
    public function summary(): string
    {
        return 'Set upload quotas per role';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function body(): ?Schema
    {
        return Schema::object(['quotas' => Schema::any()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $quotas = $request->body['quotas'] ?? null;
        if (!is_array($quotas)) {
            throw ApiProblem::validation(['quotas' => ['Megabytes by role.']]);
        }
        $errors = $this->storage()->setQuotas($quotas, $request->userId());
        if ($errors !== []) {
            throw ApiProblem::validation(array_combine(array_map(static fn($r) => "quotas.{$r}", array_keys($errors)), array_map(static fn($m) => [$m], $errors)));
        }

        return $this->overview();
    }
}
