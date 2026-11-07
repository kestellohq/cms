<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Console\Command;
use Throwable;

/**
 * plugin:activate <slug> / theme:activate <slug>
 */
final class PackageActivateCommand extends Command
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly PackageType $type,
    ) {
        $this->name = $type->value . ':activate';
        $this->description = $type === PackageType::Plugin
            ? 'Activate a plugin (runs its migrations and activate hook)'
            : 'Switch the active theme';
    }

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);

        if ($slug === null) {
            $this->output->error("  Usage: {$this->name} <slug>");
            return 1;
        }

        try {
            $package = $this->type === PackageType::Plugin
                ? $this->extensions->activatePlugin($slug)
                : $this->extensions->activateTheme($slug);
        } catch (Throwable $e) {
            $this->output->error('  ' . str_replace("\n", "\n  ", $e->getMessage()));
            return 1;
        }

        $this->output->success("  Activated {$package->manifest->name} {$package->version()}");

        return 0;
    }
}
