<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Console\Command;

/**
 * plugin:list / theme:list
 */
final class PackageListCommand extends Command
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly PackageType $type,
    ) {
        $this->name = $type->value . ':list';
        $this->description = 'List installed ' . $type->value . 's and their status';
    }

    public function handle(array $arguments, array $options): int
    {
        $packages = $this->type === PackageType::Plugin ? $this->extensions->plugins() : $this->extensions->themes();
        $rejected = $this->extensions->rejectedPlugins();
        $rows = [];

        foreach ($packages as $slug => $package) {
            $owner = $package->owner();
            $status = match (true) {
                $this->extensions->suspensions()->isSuspended($owner) => 'suspended',
                $this->type === PackageType::Plugin && isset($rejected[$slug]) => 'error',
                $this->type === PackageType::Plugin => $this->extensions->isPluginEnabled($slug) ? 'active' : 'inactive',
                default => $this->extensions->configuredTheme() === $slug ? 'active' : 'inactive',
            };

            $rows[] = [
                $slug,
                $package->manifest->name,
                $package->version(),
                $status,
                $this->type === PackageType::Theme
                    ? ($package->manifest->parent ?? '-')
                    : (implode(', ', array_keys($package->manifest->dependencies)) ?: '-'),
            ];
        }

        if ($rows === []) {
            $this->output->muted("  No {$this->type->value}s installed in content/{$this->type->value}s/.");
        } else {
            $this->output->table(['Slug', 'Name', 'Version', 'Status', $this->type === PackageType::Theme ? 'Parent' : 'Depends on'], $rows);
        }

        foreach ($rejected as $slug => $problems) {
            if (isset($packages[$slug])) {
                $this->output->warning("  {$slug}: " . implode('; ', $problems));
            }
        }

        foreach ($this->extensions->suspensions()->all() as $owner => $info) {
            if (str_starts_with($owner, $this->type->value . ':')) {
                $this->output->warning("  {$owner} suspended {$info['at']}: {$info['reason']}");
            }
        }

        foreach ($this->extensions->discoveryErrors() as $error) {
            $this->output->error('  ' . str_replace("\n", "\n  ", $error));
        }

        return 0;
    }
}
