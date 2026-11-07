<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Upload a core release zip for review: version, signature and what would block it, with a token for POST /updates/core. */
final class UploadCoreRelease extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/core/upload');
    }
    public function summary(): string
    {
        return 'Upload a core release for review';
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
            throw ApiProblem::validation(['file' => ['Choose a Kestello release zip to upload.']]);
        }
        $release = $this->attempt(fn() => $this->core()->check((string) $file['tmp_name'], ['allow_unsigned' => $this->installer()->adminMayInstallUnsigned()]));
        $token = $this->attempt(fn() => $this->installer()->hold((string) $file['tmp_name']));

        return ['token' => $token, 'release' => $release];
    }
}
