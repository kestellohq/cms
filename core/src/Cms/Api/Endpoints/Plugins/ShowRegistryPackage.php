<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Registry\RegistryCatalog;

/**
 * One plugin from the registry in full: its description, who publishes it, the version this site
 * would install (or update to) with what that version declares, and its recent versions.
 */
final class ShowRegistryPackage extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/plugins/registry/{slug}')->where('slug', self::SLUG);
    }
    public function summary(): string
    {
        return 'Show a plugin from the registry';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = (string) $request->param('slug');
        $client = $this->registry() ?? throw ApiProblem::notFound('The registry is turned off on this site.');
        $index = $this->registryIndex($client);
        $entry = $index->packages[$slug] ?? null;
        if ($entry === null || $entry['type'] !== 'plugin' || $entry['paid']) {
            throw ApiProblem::notFound("The registry doesn't list the plugin \"{$slug}\".");
        }
        $file = $this->attempt(fn() => $client->package($slug)) ?? throw ApiProblem::notFound("The registry doesn't list the plugin \"{$slug}\".");
        $packages = PackagesModule::registryPackages($this->cms()->container) ?? throw ApiProblem::notFound('The registry is turned off on this site.');

        $plugin = $this->extensions()->plugins()[$slug] ?? null;
        $installed = $plugin === null ? null : ['version' => $plugin->version(), 'active' => $this->extensions()->isPluginEnabled($slug)];
        // the newest version this site can run; "update" then says whether it is newer than the installed one
        $choice = $this->attempt(fn() => $packages->offer('plugin', $slug, null));

        return RegistryCatalog::details($file, $index->organisations[$entry['organisation']] ?? null, $choice['offer'], $choice['needs_core'], $installed);
    }
}
