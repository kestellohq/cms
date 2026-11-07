<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Deactivate (its data stays). Refused while an active plugin depends on it. */
final class DeactivatePlugin extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/{slug}/deactivate')->where('slug', self::SLUG);
    }
    public function summary(): string
    {
        return 'Deactivate a plugin';
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
        $this->attempt(fn() => $this->extensions()->deactivatePlugin($plugin->slug()));
        $this->cms()->audit->record('plugin.deactivated', ['plugin', $plugin->slug()], ['version' => $plugin->version()], $request->userId());

        return ['deactivated' => $plugin->slug()] + $this->overview();
    }
}
