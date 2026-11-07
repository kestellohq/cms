<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Install a reviewed upload (token from POST /plugins/upload), optionally replacing an inactive copy and activating it. */
final class InstallPackage extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/install');
    }
    public function summary(): string
    {
        return 'Install an uploaded plugin';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'token' => Schema::string()->max(32),
            'replace' => Schema::bool()->optional(),
            'activate' => Schema::bool()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $token = (string) $request->input('token');
        $zip = $this->attempt(fn() => $this->installer()->held($token));
        $result = $this->attempt(fn() => $this->installer()->install($zip, [
            'replace' => (bool) $request->input('replace', false),
            'allow_unsigned' => $this->installer()->adminMayInstallUnsigned(),
        ]));
        $this->installer()->release($token);
        $package = $result['package'];
        $this->cms()->audit->record($result['replaced'] ? 'plugin.replaced' : 'plugin.installed', ['plugin', $package->slug()], [
            'version' => $package->version(), 'signature' => $result['check']['signature']['status'], 'key' => $result['check']['signature']['key'],
        ], $request->userId());

        $activated = false;
        $problem = null;
        if ($request->input('activate', false)) {
            try {
                $this->extensions()->activatePlugin($package->slug());
                $this->cms()->audit->record('plugin.activated', ['plugin', $package->slug()], ['version' => $package->version()], $request->userId());
                $activated = true;
            } catch (\RuntimeException $e) {
                $problem = $e->getMessage();       // installed, but stays off
            }
        }

        return ['installed' => $package->slug(), 'replaced' => $result['replaced'], 'activated' => $activated, 'activation_problem' => $problem] + $this->overview();
    }
}
