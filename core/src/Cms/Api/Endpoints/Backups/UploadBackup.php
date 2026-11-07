<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Backups;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Add a backup from another site (multipart field "file"), to restore it here. */
final class UploadBackup extends BackupEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/backups/upload');
    }
    public function summary(): string
    {
        return 'Upload a backup from another site';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [6, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        self::onlyAdministrators($request);
        $file = $request->http->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
            $tooBig = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw ApiProblem::validation(['file' => [$tooBig
                ? 'The file is larger than this server accepts (' . ini_get('upload_max_filesize') . '). Copy it to the server and use: bin/cms backup:restore <file.zip>'
                : 'Choose a backup zip to upload.']]);
        }
        $added = $this->attempt(fn() => $this->backups()->import((string) $file['tmp_name']));
        $this->cms()->audit->record('backup.uploaded', ['backup', $added['name']], ['size' => $added['size']], $request->userId());

        return ['uploaded' => $added['name']] + $this->overview();
    }
}
