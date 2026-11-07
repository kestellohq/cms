<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** Download a backup (it holds every row, password hashes included: recent sign-in). */
final class DownloadBackup extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/backups/{name}/download')->where('name', self::NAME);
    }
    public function summary(): string
    {
        return 'Download a backup';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [20, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $name = (string) $request->param('name');
        if (!$this->backups()->exists($name)) {
            throw ApiProblem::notFound('There is no backup with that name.');
        }
        // streamed from its disk: no copy is left in storage/tmp
        [$handle, $size] = $this->attempt(fn() => $this->backups()->open($name));
        $this->cms()->audit->record('backup.downloaded', ['backup', $name], [], $request->userId());

        return Response::stream($handle, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
