<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete an inactive plugin: its uninstall hook, its tables, settings and files. */
final class DeletePlugin extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/plugins/{slug}')->where('slug', self::SLUG);
    }
    public function summary(): string
    {
        return 'Delete a plugin and its data';
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
        $plugin = $this->plugin((string) $request->param('slug'));
        if ($this->extensions()->isPluginEnabled($plugin->slug())) {
            throw ApiProblem::conflict("Deactivate {$plugin->manifest->name} before deleting it.");
        }
        $this->attempt(fn() => $this->extensions()->uninstallPlugin($plugin->slug()));
        $this->cms()->audit->record('plugin.deleted', ['plugin', $plugin->slug()], ['version' => $plugin->version()], $request->userId());

        return ['deleted' => $plugin->slug()] + $this->overview();
    }
}
