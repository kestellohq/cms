<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Framework\Console\Command;

/**
 * package:license <plugin:slug|theme:slug> [--remove]: save (read from the input, so it stays out
 * of the shell history) or remove the license key a paid plugin or theme's update feed needs.
 */
final class PackageLicenseCommand extends Command
{
    protected string $name = 'package:license';
    protected string $description = 'Save a plugin or theme license key (typed or piped in) or remove it: package:license <plugin:slug|theme:slug> [--remove]';

    public function handle(array $arguments, array $options): int
    {
        $owner = $this->argument($arguments, 0);
        if ($owner === null || preg_match('/^(plugin|theme):[a-z0-9]+(?:-[a-z0-9]+)*$/', $owner) !== 1) {
            $this->output->error('  Usage: package:license <plugin:slug|theme:slug> [--remove]');

            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $licenses = $cms->container->make(PackageLicenses::class);
        if ($this->hasFlag($options, 'remove')) {
            $licenses->remove($owner);
            $this->output->success("  The license key for {$owner} was removed.");

            return 0;
        }
        if (stream_isatty(STDIN)) {
            $this->output->line("  License key for {$owner}:");
        }
        $key = trim((string) fgets(STDIN));
        try {
            $licenses->set($owner, $key);
        } catch (\InvalidArgumentException $e) {
            $this->output->error('  ' . $e->getMessage());

            return 1;
        }
        $this->output->success("  Saved the license key for {$owner} (ending " . $licenses->describe($owner)['last4'] . '). Check it with package:check-updates ' . $owner . '.');

        return 0;
    }
}
