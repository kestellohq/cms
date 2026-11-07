<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Console\Command;

/** package:build <dir> [--out=dist] [--key=<secret-key-file>] */
final class PackageBuildCommand extends Command
{
    protected string $name = 'package:build';
    protected string $description = 'Build <slug>-<version>.zip from a plugin or theme directory: package:build <dir> [--out=dist] [--key=<file>]';

    public function handle(array $arguments, array $options): int
    {
        $dir = $this->argument($arguments, 0);
        if ($dir === null || !is_dir($dir)) {
            $this->output->error('  Usage: package:build <plugin-or-theme-directory> [--out=dist] [--key=<secret-key-file>]');
            return 1;
        }
        try {
            $built = PackageBuilder::build($dir, (string) $this->option($options, 'out', 'dist'));
            $key = $this->option($options, 'key');
            if ($key !== null) {
                PackageSignature::sign($built['zip'], SignKey::read($key));
                $built['sha256'] = (string) hash_file('sha256', $built['zip']);
                file_put_contents($built['zip'] . '.sha256', $built['sha256'] . '  ' . basename($built['zip']) . "\n");
            }
        } catch (\Throwable $e) {
            $this->output->error('  ' . str_replace("\n", "\n  ", $e->getMessage()));
            return 1;
        }
        foreach ($built['findings'] as $f) {
            $this->output->warning("  {$f['file']}:{$f['line']} {$f['message']}");
        }
        $this->output->success("  {$built['zip']}" . ($key !== null ? ' (signed)' : ''));
        $this->output->muted("  sha256 {$built['sha256']}");

        return 0;
    }
}
