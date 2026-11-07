<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

final class DeleteBackup extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/backups/{name}')->where('name', self::NAME);
    }
    public function summary(): string
    {
        return 'Delete a backup';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = (string) $request->param('name');
        if (!$this->backups()->exists($name)) {
            throw ApiProblem::notFound('There is no backup with that name.');
        }
        $this->attempt(fn() => $this->backups()->delete($name));
        $this->cms()->audit->record('backup.deleted', ['backup', $name], [], $request->userId());

        return $this->overview();
    }
}
