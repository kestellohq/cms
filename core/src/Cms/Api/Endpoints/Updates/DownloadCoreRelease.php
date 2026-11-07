<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Download a Kestello release from GitHub for review: the same answer as uploading it
 * (version, signature, what would block it, and a token for POST /updates/core).
 * The zip's SHA-256 must match the published checksum; the signature is checked after.
 */
final class DownloadCoreRelease extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/core/download');
    }
    public function summary(): string
    {
        return 'Download a Kestello release for review';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [5, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object(['version' => Schema::string()->max(40)->pattern('\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $zip = $this->attempt(fn() => $this->checker()->download((string) $request->input('version')));
        try {
            $release = $this->attempt(fn() => $this->core()->check($zip, ['allow_unsigned' => $this->installer()->adminMayInstallUnsigned()]));
            $token = $this->attempt(fn() => $this->installer()->hold($zip));
        } finally {
            @unlink($zip);
        }

        return ['token' => $token, 'release' => $release];
    }
}
