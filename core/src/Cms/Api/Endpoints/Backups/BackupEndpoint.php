<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;

/**
 * Base for /backups. A backup holds every row of the site, password
 * hashes included: capability backups.manage and the "admin" token scope.
 *
 * @internal
 */
abstract class BackupEndpoint extends Endpoint
{
    public const string NAME = '[a-z0-9-]{1,120}\.zip';

    public function capability(): ?string
    {
        return 'backups.manage';
    }

    /**
     * A backup holds the whole user table: restoring one (or bringing one in
     * from elsewhere) decides who the administrators are, so only someone who
     * already is one may do it. Making and downloading backups stays with
     * backups.manage.
     */
    protected static function onlyAdministrators(\Kestello\Cms\Api\ApiRequest $request): void
    {
        if (!in_array('*', $request->principal->capabilities, true)) {
            throw \Kestello\Cms\Api\ApiProblem::forbidden('Only administrators can restore or upload backups.');
        }
    }
    public function scopes(): array
    {
        return ['admin'];
    }
    public function tags(): array
    {
        return ['backups'];
    }

    protected function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function backups(): Backups
    {
        return $this->cms()->container->make(Backups::class);
    }

    protected function attempt(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (ContentException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'forbidden' => ApiProblem::forbidden($e->getMessage()),
                default => ApiProblem::conflict($e->getMessage()),
            };
        } catch (\RuntimeException $e) {
            throw ApiProblem::conflict($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    protected function overview(): array
    {
        $disk = 'backups';
        $config = $this->cms()->storage->config($disk) ?? [];

        return [
            'backups' => $this->backups()->all(),
            'settings' => [
                'schedule' => BackupModule::option('backups_schedule', 'daily'),
                'files' => BackupModule::option('backups_files', '0') === '1',
                'keep' => BackupModule::keep(),
            ],
            'disk' => ['name' => $disk, 'driver' => (string) ($config['driver'] ?? 'local')],
            'site_name' => (string) \Kestello\Framework\Settings\Settings::getInstance()->get('site_name', 'Kestello'),
        ];
    }
}
