<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\Update\Updater;

/**
 * Download the update a plugin or theme's source offers, for review: the same answer as an
 * upload (signature, author, permissions, code check, what would block it) and a token for
 * POST /updates/packages/update. The zip's SHA-256 must match the source's.
 */
final class DownloadPackageUpdate extends PackageUpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/packages/download');
    }

    public function summary(): string
    {
        return 'Download a plugin or theme update for review';
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
        return Schema::object(['owner' => self::ownerSchema()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $owner = (string) $request->input('owner');
        $this->source($owner);
        $download = $this->attempt(fn() => $this->updates()->download($owner));
        try {
            $options = ['allow_unsigned' => $this->installer()->adminMayInstallUnsigned()];
            $package = $this->attempt(fn() => $this->cms()->container->make(Updater::class)->check($download['path'], $options));
            if ($package['type'] . ':' . $package['slug'] !== $owner) {
                $package['problems'][] = "The download is {$package['type']} \"{$package['slug']}\", not the package that was asked for.";
            }
            $token = $this->attempt(fn() => $this->installer()->hold($download['path']));
        } finally {
            @unlink($download['path']);
        }
        $sig = $package['signature']['status'];

        return [
            'token' => $token,
            'owner' => $owner,
            'package' => $package,
            'allowed' => $package['problems'] === [] && ($sig === 'signed' || ($sig !== 'tampered' && $this->installer()->adminMayInstallUnsigned())),
        ];
    }
}
