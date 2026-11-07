<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Console\Command;
use Throwable;

/**
 * plugin:install <path> [--force] [--activate]   /   theme:install <path> …
 */
final class PackageInstallCommand extends Command
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly PackageType $type,
    ) {
        $this->name = $type->value . ':install';
        $this->description = "Install a {$type->value} from a directory or a package zip (--force to replace, --activate)";
    }

    public function handle(array $arguments, array $options): int
    {
        $source = $this->argument($arguments, 0);

        if ($source === null || (!is_dir($source) && !(is_file($source) && str_ends_with(strtolower($source), '.zip')))) {
            $this->output->error("  Usage: {$this->name} <{$this->type->value}-directory|package.zip> [--force] [--activate]");
            return 1;
        }

        try {
            if (is_file($source)) {
                $package = $this->installZip($source, $this->hasFlag($options, 'force'));
                if ($package === null) {
                    return 1;
                }
            } else {
                $package = $this->extensions->install($source, $this->type, $this->hasFlag($options, 'force'));
            }
            $this->output->success("  Installed {$package->manifest->name} {$package->version()} → content/{$this->type->value}s/{$package->slug()}");
            $this->disclose($package->manifest->permissions, $package->manifest->replaces);

            if ($this->hasFlag($options, 'activate')) {
                $this->type === PackageType::Plugin
                    ? $this->extensions->activatePlugin($package->slug())
                    : $this->extensions->activateTheme($package->slug());
                $this->output->success("  Activated {$package->slug()}");
            }
        } catch (Throwable $e) {
            $this->output->error('  ' . str_replace("\n", "\n  ", $e->getMessage()));
            return 1;
        }

        return 0;
    }

    /** A zip: signature checked (tampered refused, unsigned allowed: a developer is installing), plugin:check errors refused. */
    private function installZip(string $zip, bool $force): ?\Kestello\Cms\Extension\Package
    {
        $cms = \Kestello\Cms\Cms::instance() ?? throw new \RuntimeException('Not booted.');
        try {
            $result = (new \Kestello\Cms\Package\PackageInstaller($cms))->install($zip, ['replace' => $force, 'allow_unsigned' => true, 'replace_active' => $force]);
        } catch (\Kestello\Cms\Content\ContentException $e) {
            $this->output->error('  ' . implode("\n  ", $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()]));
            return null;
        }
        if ($result['check']['type'] !== $this->type->value) {
            $this->output->warning("  Note: that zip holds a {$result['check']['type']}.");
        }
        $sig = $result['check']['signature'];
        $this->output->muted('  ' . match ($sig['status']) {
            \Kestello\Cms\Package\PackageSignature::SIGNED => "Signed by {$sig['name']}.",
            \Kestello\Cms\Package\PackageSignature::UNTRUSTED => "Signed by key {$sig['key']}, which this site does not trust.",
            default => 'Not signed.',
        });

        return $result['package'];
    }

    /**
     * Show what the package declares it will do (disclosure, see spec/modularity.md §8.2).
     *
     * @param array<string, mixed> $permissions
     * @param list<string>         $replaces
     */
    private function disclose(array $permissions, array $replaces): void
    {
        if ($permissions === [] && $replaces === []) {
            return;
        }

        $this->output->info('  Declared permissions:');
        foreach ($permissions as $area => $value) {
            $this->output->line("    {$area}: " . (is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value));
        }
        foreach ($replaces as $contract) {
            $this->output->line("    replaces: {$contract}");
        }
    }
}
