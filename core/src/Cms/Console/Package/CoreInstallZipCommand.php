<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Package\Update\InstallZip;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;

/** core:install-zip [--out=dist]: the zip people download to install Kestello, kestello-<version>.zip. */
final class CoreInstallZipCommand extends Command
{
    protected string $name = 'core:install-zip';
    protected string $description = 'Build the zip people download to install Kestello: core:install-zip [--out=dist]';

    /** Works on a checkout that was never installed (the release workflow builds from one). */
    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        try {
            $built = InstallZip::build($this->paths->root(), (string) ($this->option($options, 'out') ?? $this->paths->root('dist')));
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $this->output->success("  {$built['zip']} ({$built['files']} files)");

        return 0;
    }
}
