<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\PackageChecker;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;

/**
 * plugin:check <slug|path> [--theme]
 */
final class PackageCheckCommand extends Command
{
    protected string $name = 'plugin:check';
    protected string $description = 'Statically check a plugin (or theme with --theme) for unsafe code';

    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $target = $this->argument($arguments, 0);
        $type = $this->hasFlag($options, 'theme') ? PackageType::Theme : PackageType::Plugin;

        if ($target === null) {
            $this->output->error('  Usage: plugin:check <slug|path> [--theme]');
            return 1;
        }

        $dir = is_dir($target) ? $target : ($type === PackageType::Plugin ? $this->paths->plugins() : $this->paths->themes()) . '/' . $target;

        if (!is_dir($dir)) {
            $this->output->error("  Not found: {$target}");
            return 1;
        }

        $findings = (new PackageChecker())->check($dir, $type);
        $errors = 0;

        foreach ($findings as $f) {
            $location = $f['line'] > 0 ? "{$f['file']}:{$f['line']}" : $f['file'];
            if ($f['level'] === 'error') {
                $errors++;
                $this->output->error("  ✗ {$location}  {$f['message']}");
            } else {
                $this->output->warning("  ! {$location}  {$f['message']}");
            }
        }

        if ($findings === []) {
            $this->output->success('  No issues found.');
        } else {
            $this->output->line(sprintf('  %d error(s), %d warning(s)', $errors, count($findings) - $errors));
        }

        return $errors > 0 ? 1 : 0;
    }
}
