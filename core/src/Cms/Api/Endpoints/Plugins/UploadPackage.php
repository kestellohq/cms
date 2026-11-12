<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/**
 * Upload a plugin zip (multipart "file"). Nothing is installed: the answer
 * says what the package is, who signed it and what it declares, with a
 * token for POST /plugins/install, or POST /plugins/update when an active
 * copy is installed ("update" then says whether it can be applied).
 */
final class UploadPackage extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/upload');
    }
    public function summary(): string
    {
        return 'Upload a plugin zip for review';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $file = $request->http->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
            throw ApiProblem::validation(['file' => ['Choose a plugin zip to upload.']]);
        }

        return $this->review((string) $file['tmp_name'], 'That zip holds a theme. Themes are added under Appearance → Themes.');
    }
}
