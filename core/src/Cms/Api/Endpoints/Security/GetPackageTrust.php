<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\PackageTrust;
use Kestello\Cms\Package\Update\KeyPins;

/**
 * Who the site trusts to give it code: the publishers whose signed plugins and themes the admin
 * installs, whether it takes unsigned ones, Kestello's release keys, and who signed each
 * installed plugin and theme. The keys come from the server's config files, never the admin.
 */
final class GetPackageTrust extends SecurityEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/security/packages');
    }
    public function summary(): string
    {
        return 'Who the site trusts for plugins, themes and updates';
    }

    public function response(): ?Schema
    {
        $key = Schema::object(['id' => Schema::string()->description('First 16 hex characters of the key\'s SHA-256'), 'name' => Schema::string()]);

        return Schema::object([
            'admin_unsigned' => Schema::bool()->description('app.packages.admin_unsigned: the admin installs unsigned zips too'),
            'publishers' => Schema::list($key)->description('app.packages.trusted_keys'),
            'release_keys' => Schema::list($key)->description('release.keys: what Kestello\'s own updates must be signed with'),
            'packages' => Schema::list(Schema::object([
                'type' => Schema::enum(['plugin', 'theme']), 'slug' => Schema::string(), 'name' => Schema::string(), 'version' => Schema::string(), 'active' => Schema::bool(),
                'signed_by' => Schema::list(Schema::object(['id' => Schema::string(), 'name' => Schema::string()->nullable()->description('null: the key is no longer trusted')]))
                    ->description('Keys allowed to sign its next version besides its organisation\'s; empty when it was installed without a signature or from the registry with no hand-over keys'),
                'organisation' => Schema::string()->nullable()->description('Installed from the registry: the organisation whose certified keys sign its next version'),
                'updates' => Schema::string()->nullable()->description('Where new versions come from'),
            ])),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = self::cms();
        $extensions = $cms->extensions();
        // switched on (plugins) or in use (the theme and its parents), as the site has them stored
        $active = [];
        foreach ($extensions->plugins() as $p) {
            $active[$p->owner()] = $extensions->stateFor($p->type(), $p->slug())?->active === true;
        }
        foreach ($extensions->themeChain() as $t) {
            $active[$t->owner()] = true;
        }
        $pins = $cms->container->has(KeyPins::class) ? $cms->container->make(KeyPins::class) : KeyPins::inSettings();

        return (new PackageTrust(
            PackageSignature::trustedKeys($cms->config->get('app.packages.trusted_keys', [])),
            PackageSignature::trustedKeys($cms->config->get('release.keys', []), 'release.keys'),
            (bool) $cms->config->get('app.packages.admin_unsigned', false),
            [...array_values($extensions->plugins()), ...array_values($extensions->themes())],
            $active,
            $pins,
        ))->report();
    }
}
