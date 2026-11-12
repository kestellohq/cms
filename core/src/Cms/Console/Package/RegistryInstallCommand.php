<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Cms\Package\Registry\RegistryException;
use Kestello\Framework\Console\Command;
use Throwable;

/**
 * registry:install <name> [--version=1.5.0] [--prerelease] [--activate]
 *
 * Installs a plugin or theme from the registry: the newest version this site can run (or the one
 * asked for), downloaded, checked against the registry's size and SHA-256, then installed only when
 * the registry vouches for it, exactly as the admin would. Updates then come from the registry.
 */
final class RegistryInstallCommand extends Command
{
    protected string $name = 'registry:install';
    protected string $description = 'Install a plugin or theme from the registry: registry:install <name> [--version=1.5.0] [--prerelease] [--activate]';

    public function handle(array $arguments, array $options): int
    {
        $slug = (string) $this->argument($arguments, 0);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->output->error('  Usage: registry:install <name> [--version=1.5.0] [--prerelease] [--activate]');

            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $packages = PackagesModule::registryPackages($cms->container);
        if ($packages === null) {
            $this->output->error('  The registry is turned off on this site (registry.enabled).');

            return 1;
        }
        $client = $cms->container->make(RegistryClient::class);
        $installer = $cms->container->make(PackageInstaller::class);
        $zip = null;
        try {
            $index = $client->index() ?? $this->refresh($client);
            $entry = $index->packages[$slug] ?? throw new RegistryException("The registry doesn't list \"{$slug}\".");
            $type = PackageType::from($entry['type']);
            if (($installed = $installer->installed($type, $slug)) !== null) {
                $this->output->error("  {$entry['name']} {$installed['version']} is already installed. Update it under Site → Updates.");

                return 1;
            }
            $version = $this->option($options, 'version') ?? $packages->choose($entry['type'], $slug, null, $this->hasFlag($options, 'prerelease'))
                ?? throw new RegistryException("The registry has no version of {$entry['name']} that this site can run (Kestello {$cms->version()}, PHP " . PHP_VERSION . ').');
            $this->output->muted("  Downloading {$entry['name']} {$version} from the registry…");
            $zip = $packages->download($entry['type'], $slug, $version);
            $result = $installer->install($zip, ['require_registry' => true]);
        } catch (ContentException $e) {
            $this->output->error('  ' . implode("\n  ", $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()]));

            return 1;
        } catch (Throwable $e) {
            $this->output->error('  ' . $e->getMessage());

            return 1;
        } finally {
            if ($zip !== null) {
                @unlink($zip);
            }
        }

        $package = $result['package'];
        $registry = $result['check']['registry'];
        $this->output->success("  Installed {$package->manifest->name} {$package->version()}, published by {$registry['organisation_name']} ({$registry['organisation']}).");
        foreach ($registry['advisories'] as $a) {
            $this->output->warning("  Security advisory {$a['id']} ({$a['severity']}): {$a['title']} {$a['url']}");
        }
        if ($this->hasFlag($options, 'activate')) {
            try {
                $type === PackageType::Plugin ? $cms->extensions()->activatePlugin($package->slug()) : $cms->extensions()->activateTheme($package->slug());
                $this->output->success("  Activated {$package->slug()}.");
            } catch (Throwable $e) {
                $this->output->error("  Installed, but not activated: {$e->getMessage()}");

                return 1;
            }
        }

        return 0;
    }

    /** @throws RegistryException */
    private function refresh(RegistryClient $client): \Kestello\Cms\Package\Registry\RegistryIndex
    {
        $status = $client->refresh();

        return $client->index() ?? throw new RegistryException($status['error'] ?? 'The registry could not be read.');
    }
}
