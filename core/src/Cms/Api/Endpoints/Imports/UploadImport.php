<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Upload a WordPress export and see what importing it would do. */
final class UploadImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/imports');
    }

    public function summary(): string
    {
        return 'Upload a WordPress export and see what importing it would do';
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
        $file = $request->http->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
            $tooBig = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw ApiProblem::validation(['file' => [$tooBig
                ? 'The file is larger than this server accepts (' . ini_get('upload_max_filesize') . '). Copy it to the server and use: bin/cms import:wordpress <file.xml>'
                : 'Choose the file WordPress made under Tools → Export.']]);
        }

        return self::present($this->attempt(fn() => $this->imports()->analyze((string) $file['tmp_name'], $request->principal, move: true)));
    }
}
