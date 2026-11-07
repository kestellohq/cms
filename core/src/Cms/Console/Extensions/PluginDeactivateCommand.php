<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Framework\Console\Command;
use Throwable;

final class PluginDeactivateCommand extends Command
{
    protected string $name = 'plugin:deactivate';
    protected string $description = 'Deactivate a plugin (keeps its data)';

    public function __construct(
        private readonly ExtensionManager $extensions,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);

        if ($slug === null) {
            $this->output->error('  Usage: plugin:deactivate <slug>');
            return 1;
        }

        try {
            $this->extensions->deactivatePlugin($slug);
        } catch (Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }

        $this->output->success("  Deactivated {$slug}");

        return 0;
    }
}
