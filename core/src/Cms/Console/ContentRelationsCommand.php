<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\EntryRelations;
use Kestello\Framework\Console\Command;

/** Record every entry's links again, so each entry knows what links to it (after an upgrade, import or restore). */
final class ContentRelationsCommand extends Command
{
    protected string $name = 'content:relations';
    protected string $description = 'Rebuild which entries link to which (two-way relationships)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        try {
            $links = $cms->container->make(EntryRelations::class)->rebuild();
        } catch (\Throwable $e) {
            $this->output->error('  Could not rebuild the links: ' . $e->getMessage());

            return 1;
        }
        $this->output->success("  {$links} link(s) recorded.");

        return 0;
    }
}
