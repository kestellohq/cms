<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;

/**
 * Clear all cached data and compiled templates.
 */
final class CacheClearCommand extends Command
{
    protected string $name = 'cache:clear';
    protected string $description = 'Clear all cache and compiled template files';

    public function __construct(
        private readonly string $basePath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $cleared = 0;

        // Clear file cache
        $cleared += $this->clearDirectory($this->basePath . '/storage/cache', 'Cache');

        // Clear compiled templates
        $cleared += $this->clearDirectory($this->basePath . '/storage/compiled', 'Compiled templates');

        $this->output->newLine();
        $this->output->success("  Cleared {$cleared} file(s).");

        return 0;
    }

    private function clearDirectory(string $path, string $label): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $files = glob($path . '/*');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep') {
                unlink($file);
                $count++;
            }
        }

        $this->output->info("  {$label}: {$count} file(s) cleared");

        return $count;
    }
}
