<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Package\Registry\RegistryTrust;
use Kestello\Cms\Package\Update\Updater;

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
        $package = $this->attempt(fn() => $this->installer()->inspect((string) $file['tmp_name']));
        if ($package['type'] !== 'plugin') {
            throw ApiProblem::validation(['file' => ['That zip holds a theme. Themes are added under Appearance → Themes.']]);
        }
        $token = $this->attempt(fn() => $this->installer()->hold((string) $file['tmp_name']));
        $sig = $package['signature']['status'];

        $update = null;
        if ($package['installed'] !== null && $package['installed']['active']) {
            $zip = $this->installer()->held($token);
            $update = ['from' => $package['installed']['version'], 'problems' => $this->attempt(fn() => $this->cms()->container->make(Updater::class)->check($zip)['problems'])];
        }

        return [
            'token' => $token,
            'package' => $package,
            'update' => $update,
            // a version the registry refuses (withdrawn, revoked key, …) never installs, whatever the signature says
            'allowed' => $package['registry']['status'] !== RegistryTrust::REFUSED
                && ($sig === 'signed' || ($sig !== 'tampered' && $this->installer()->adminMayInstallUnsigned())),
        ];
    }
}
