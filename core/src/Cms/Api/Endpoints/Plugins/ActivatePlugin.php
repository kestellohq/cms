<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Activate: runs the plugin's migrations, then a trial register/boot; a plugin that fails stays off. */
final class ActivatePlugin extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/{slug}/activate')->where('slug', self::SLUG);
    }
    public function summary(): string
    {
        return 'Activate a plugin';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [20, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $plugin = $this->plugin((string) $request->param('slug'));
        $this->attempt(fn() => $this->extensions()->activatePlugin($plugin->slug()));
        $this->cms()->audit->record('plugin.activated', ['plugin', $plugin->slug()], ['version' => $plugin->version()], $request->userId());

        return ['activated' => $plugin->slug()] + $this->overview();
    }
}
