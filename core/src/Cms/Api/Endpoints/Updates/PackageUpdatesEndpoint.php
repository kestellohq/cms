<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Cms\Package\Update\PackageUpdates;

/** Base for /updates/packages: plugin and theme updates from the sources their manifests name. @internal */
abstract class PackageUpdatesEndpoint extends UpdatesEndpoint
{
    protected function updates(): PackageUpdates
    {
        return $this->cms()->container->make(PackageUpdates::class);
    }

    protected function licenses(): PackageLicenses
    {
        return $this->cms()->container->make(PackageLicenses::class);
    }

    protected static function ownerSchema(): Schema
    {
        return Schema::string()->max(80)->pattern('^(plugin|theme):' . self::SLUG . '$');
    }

    /** An installed package that names where its updates come from. */
    protected function source(string $owner): void
    {
        if ($this->installedVersion($owner) === null) {
            throw ApiProblem::notFound('There is no plugin or theme with that name.');
        }
        foreach (\Kestello\Cms\Package\PackagesModule::installedPackages($this->cms()) as $p) {
            if ($p['owner'] === $owner && $p['updates'] !== null) {
                return;
            }
        }

        throw ApiProblem::validation(['owner' => ['This package does not say where its updates come from. Update it by uploading the new version.']]);
    }
}
