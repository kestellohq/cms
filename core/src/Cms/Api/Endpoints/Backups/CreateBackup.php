<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Back up now: the database, and files unless files=false. */
final class CreateBackup extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/backups');
    }
    public function summary(): string
    {
        return 'Make a backup';
    }
    public function rateLimit(): ?array
    {
        return [6, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'files' => Schema::bool()->optional(),
            'note' => Schema::string()->max(200)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $made = $this->attempt(fn() => $this->backups()->create(['files' => (bool) $request->input('files', true), 'note' => (string) $request->input('note', '')]));
        $this->cms()->audit->record('backup.created', ['backup', $made['name']], ['files' => $made['files'], 'size' => $made['size']], $request->userId());

        return ['created' => $made['name']] + $this->overview();
    }
}
