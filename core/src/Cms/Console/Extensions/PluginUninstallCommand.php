<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Framework\Console\Command;
use Throwable;

final class PluginUninstallCommand extends Command
{
    protected string $name = 'plugin:uninstall';
    protected string $description = 'Remove a plugin and its data (tables, settings); --keep-files, --yes';

    public function __construct(
        private readonly ExtensionManager $extensions,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);

        if ($slug === null) {
            $this->output->error('  Usage: plugin:uninstall <slug> [--keep-files] [--yes]');
            return 1;
        }

        if (!$this->hasFlag($options, 'yes')
            && !$this->output->confirm("  Permanently delete all data of \"{$slug}\" (its tables and settings)?")) {
            $this->output->muted('  Aborted.');
            return 1;
        }

        try {
            $this->extensions->uninstallPlugin($slug, deleteFiles: !$this->hasFlag($options, 'keep-files'));
        } catch (Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }

        $this->output->success("  Uninstalled {$slug}");

        return 0;
    }
}
