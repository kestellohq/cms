<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\PackagesModule;

/**
 * Download a plugin version from the registry for review: the zip is checked against the
 * registry's size and SHA-256, then answered exactly like an upload (POST /plugins/upload), with a
 * token for POST /plugins/install or /plugins/update. Nothing is installed here.
 */
final class ReviewRegistryPackage extends PluginEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/plugins/registry/{slug}/review')->where('slug', self::SLUG);
    }
    public function summary(): string
    {
        return 'Download a plugin from the registry for review';
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
            'version' => Schema::string()->max(64)->pattern('[0-9A-Za-z.+-]+'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = (string) $request->param('slug');
        $packages = PackagesModule::registryPackages($this->cms()->container) ?? throw ApiProblem::notFound('The registry is turned off on this site.');
        $zip = $this->attempt(fn() => $packages->download('plugin', $slug, (string) $request->input('version')));
        try {
            return $this->review($zip, "The registry's \"{$slug}\" is not a plugin.");
        } finally {
            @unlink($zip);
        }
    }
}
