<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Cms;
use Kestello\Framework\Console\Command;

/** Rebuild the search index (after changing searchable fields, importing content, or switching engines). */
final class SearchReindexCommand extends Command
{
    protected string $name = 'search:reindex';
    protected string $description = 'Rebuild the search index [--collection=content]';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $only = isset($options['collection']) ? [(string) $options['collection']] : null;
        $engine = $cms->search->engine()->name();
        $this->output->line("  Rebuilding with the {$engine} engine…");
        try {
            $counts = $cms->search->reindex($only);
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());

            return 1;
        }
        foreach ($counts as $collection => $n) {
            $this->output->line("  {$collection}: {$n} document(s)");
        }
        $this->output->success('  Done.');

        return 0;
    }
}
