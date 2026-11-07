<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Settings\Settings;

/**
 * Replace the site with a backup. "confirm" must be the site's name, typed.
 * Plugin and theme folders come back only from archives this site made;
 * code from anywhere else goes through the CLI (backup:restore --with-code).
 */
final class RestoreBackup extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/backups/{name}/restore')->where('name', self::NAME);
    }
    public function summary(): string
    {
        return 'Restore a backup';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [3, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object(['confirm' => Schema::string()->max(200)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        self::onlyAdministrators($request);
        $name = (string) $request->param('name');
        if (!$this->backups()->exists($name)) {
            throw ApiProblem::notFound('There is no backup with that name.');
        }
        $site = (string) Settings::getInstance()->get('site_name', 'Kestello');
        if (trim((string) $request->input('confirm')) !== $site) {
            throw ApiProblem::validation(['confirm' => ["Type the site's name, {$site}, to confirm."]]);
        }
        $user = $request->userId();
        $report = $this->attempt(fn() => $this->backups()->restoreNamed($name));

        // the audit log is the archive's now; this entry is the first after it
        $this->cms()->audit->record('backup.restored_from_admin', ['backup', $name], ['by' => $user], null);

        return ['restored' => $name, 'report' => $report];
    }
}
