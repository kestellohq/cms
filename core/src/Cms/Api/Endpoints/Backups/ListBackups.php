<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Backups, newest first, with the schedule settings. */
final class ListBackups extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/backups');
    }
    public function summary(): string
    {
        return 'List backups';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->overview();
    }
}
