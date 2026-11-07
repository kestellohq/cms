<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Cms;
use Kestello\Contracts\Cache\PageCache;
use Kestello\Framework\Console\Command;

/** Empty the page cache (after editing a theme's files by hand, for example). */
final class PageCacheClearCommand extends Command
{
    protected string $name = 'cache:pages';
    protected string $description = 'Clear the page cache';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $cms->container->make(PageCache::class)->flush();
        $this->output->success('  Page cache cleared.');

        return 0;
    }
}
