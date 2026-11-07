<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Settings\Settings;

/** How often scheduled backups run, whether they include files, and how many to keep. */
final class UpdateBackupSettings extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/backups/settings');
    }
    public function summary(): string
    {
        return 'Change the backup schedule';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'schedule' => Schema::enum(['daily', 'weekly', 'off']),
            'files' => Schema::bool(),
            'keep' => Schema::int()->min(1)->max(100),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $values = ['backups_schedule' => (string) $request->input('schedule'), 'backups_files' => $request->input('files') ? '1' : '0', 'backups_keep' => (string) (int) $request->input('keep')];
        Settings::getInstance()->setMany($values, 'backups');
        $this->cms()->audit->record('backup.settings', null, $values, $request->userId());

        return $this->overview();
    }
}
