<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Closure;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Console\Command;

/**
 * cache:clear: empties the site's cache, whichever store it uses (files,
 * APCu, Redis, the database), and the compiled templates. The page cache has
 * its own command, cache:pages.
 *
 * APCu belongs to one PHP process pool: from the command line this clears the
 * command line's APCu, not the web server's, which empties when PHP restarts.
 */
final class CacheClearCommand extends Command
{
    protected string $name = 'cache:clear';
    protected string $description = 'Clear the cache (any store) and the compiled templates';

    /**
     * @param Closure(): Store $store the site's cache store, made only when the command runs
     * @param string $compiled the compiled templates folder (Paths::compiled())
     */
    public function __construct(
        private readonly Closure $store,
        private readonly string $compiled,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        if (!($this->store)()->flush()) {
            $this->output->error('  Some of the cache could not be cleared; check that the storage folder is writable.');
            return 1;
        }
        $this->output->info('  Cache: cleared');
        $files = self::emptyFolder($this->compiled);
        $this->output->info("  Compiled templates: {$files} file(s) cleared");
        $this->output->success('  Done.');

        return 0;
    }

    /**
     * Delete everything in a folder, subfolders included, but keep the folder
     * itself and .gitkeep files. Returns the number of files deleted.
     */
    public static function emptyFolder(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->getFilename() === '.gitkeep') {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());    // left when it still holds a .gitkeep
            } elseif (@unlink($item->getPathname())) {
                $count++;
            }
        }

        return $count;
    }
}
