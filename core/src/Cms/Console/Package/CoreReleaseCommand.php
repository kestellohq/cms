<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Update\CoreRelease;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;

/** core:release [<dir>] [--out=dist] [--key=<file>]: zip this core (or the core folder <dir>) as kestello-core-<version>.zip, signed with --key. */
final class CoreReleaseCommand extends Command
{
    protected string $name = 'core:release';
    protected string $description = 'Build a core release zip from this core: core:release [<core dir>] [--out=dist] [--key=<secret key file>]';

    /** Works on a checkout that was never installed (the release workflow builds from one). */
    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        $out = (string) ($this->option($options, 'out') ?? $this->paths->root('dist'));
        try {
            $built = CoreRelease::build((string) ($this->argument($arguments, 0) ?? $this->paths->core()), $out);
            $key = $this->option($options, 'key');
            if (is_string($key) && $key !== '') {
                PackageSignature::sign($built['zip'], SignKey::read($key));
                file_put_contents($built['zip'] . '.sha256', hash_file('sha256', $built['zip']) . '  ' . basename($built['zip']) . "\n");
            }
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $this->output->success("  {$built['zip']}" . (isset($key) && is_string($key) && $key !== '' ? ' (signed)' : ' (unsigned)'));

        return 0;
    }
}
