<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Installer\ConfigTemplates;
use Kestello\Framework\Console\Command;

/**
 * Add the commented config files (cron, queue, auth, audit) a site is
 * missing. New sites get them from the installer; this is for sites
 * installed before. Existing files are never changed.
 *
 *   php bin/cms config:publish
 */
final class ConfigPublishCommand extends Command
{
    protected string $name = 'config:publish';
    protected string $description = 'Add missing config files (cron, queue, auth, audit) with every option at its default';

    public function __construct(private readonly string $configDir) {}

    public function handle(array $arguments, array $options): int
    {
        $written = ConfigTemplates::writeMissing($this->configDir);
        if ($written === []) {
            $this->output->line('  Nothing to add: config/' . implode('.php, config/', array_keys(ConfigTemplates::all())) . '.php are all there.');
            return 0;
        }
        foreach ($written as $path) {
            $this->output->success('  Created config/' . basename($path));
        }
        $this->output->muted('  Every option is at its default. Change what you need; the comments say what each one does.');

        return 0;
    }
}
